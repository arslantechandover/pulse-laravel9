<?php

namespace Laravel\Pulse\Recorders;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Http\Middleware\TagClientRequests;
use Laravel\Pulse\Pulse;
use RuntimeException;

/**
 * Fork: client-perceived duration of slow requests and where it went, as measured by the web server.
 *
 * nginx's `$request_time` (first request byte received → last response byte sent) is split into:
 * upload (→ FastCGI hand-off) + queue (→ PHP start) + DB + HTTP + app (→ response built) + download (the rest:
 * handing the response to nginx and sending it to the client). The server parts come from an internal response
 * header (`X-Pulse`, written by `TagClientRequests`) that nginx logs and removes. nginx sends one syslog line per
 * request over UDP; `pulse:check` receives the lines on every beat.
 *
 * nginx (http context, e.g. top of the site file):
 *
 *     map "$request_time|$upstream_http_x_pulse" $pulse_client_log { "~^0\." 0; "~\|$" 0; default 1; }
 *     log_format pulse_client escape=json '{"t":$msec,"rt":$request_time,"ut":"$upstream_response_time","s":$status,"b":$bytes_sent,"r":"$upstream_http_x_pulse"}';
 *
 * and in the PHP location (a location-level access_log overrides a server-level `access_log off`):
 *
 *     access_log syslog:server=127.0.0.1:5140,facility=local7,tag=pulse,severity=info pulse_client if=$pulse_client_log;
 *     fastcgi_hide_header X-Pulse;
 *
 * @internal
 */
class ClientRequests
{
    use Concerns\Thresholds,
        ConfiguresAfterResolving;

    /**
     * The internal response header carrying the route key and server timing (removed by the web server).
     */
    public const HEADER = 'X-Pulse';

    /**
     * The breakdown parts, in order (each recorded as `slow_client_request_{part}`).
     *
     * @var list<string>
     */
    public const PARTS = ['upload', 'queue', 'db', 'queries', 'http', 'app', 'download', 'bytes'];

    /**
     * The maximum number of messages handled per beat.
     */
    protected const MAX_MESSAGES_PER_BEAT = 1000;

    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = SharedBeat::class;

    /**
     * The UDP socket (false once listening has failed).
     *
     * @var resource|false|null
     */
    protected $socket = null;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Register the recorder.
     */
    public function register(Application $app): void
    {
        $this->afterResolving(
            $app,
            Kernel::class,
            fn (Kernel $kernel) => $kernel->pushMiddleware(TagClientRequests::class) // @phpstan-ignore method.notFound
        );
    }

    /**
     * Receive the web server's timing lines.
     */
    public function record(SharedBeat $event): void
    {
        if (($socket = $this->socket()) === null) {
            return;
        }

        for ($i = 0; $i < self::MAX_MESSAGES_PER_BEAT; $i++) {
            $message = @stream_socket_recvfrom($socket, 8192);

            if (! is_string($message) || $message === '') {
                return;
            }

            $this->pulse->rescue(fn () => $this->recordMessage($message));
        }
    }

    /**
     * Record one timing line, e.g. `<190>Oct 17 12:00:00 host pulse: {"t":…,"rt":…,"ut":"…","s":200,"b":…,"r":"…"}`.
     */
    protected function recordMessage(string $message): void
    {
        if (($start = strpos($message, '{')) === false) {
            return;
        }

        $line = json_decode(substr($message, $start), true);

        if (! is_array($line) || ! is_numeric($line['t'] ?? null) || ! is_numeric($line['rt'] ?? null) || ! is_string($line['r'] ?? null)) {
            return;
        }

        // 499: the client closed the connection before the response was complete - not a completed request...
        if ((int) ($line['s'] ?? 0) === 499) {
            return;
        }

        $header = json_decode(rawurldecode($line['r']), true);
        $key = $header['k'] ?? null;

        if (! is_array($key) || count($key) !== 3 || ! is_string($key[0]) || ! is_string($key[1])) {
            return;
        }

        $total = (int) round((float) $line['rt'] * 1000);

        if ($this->underThreshold($total, $key[1])) {
            return;
        }

        $parts = $this->breakdown($total, (float) $line['t'] - (float) $line['rt'], $header, $line);
        [$method, $path] = $key;
        $key = json_encode($key, flags: JSON_THROW_ON_ERROR);
        $timestamp = (int) floor((float) $line['t'] - (float) $line['rt']);

        // Recorded with an entry (not only buckets): Pulse resolves a key's text from pulse_entries, so endpoints that are
        // slow only for the client need an entry of their own...
        $this->pulse->record('slow_client_request', $key, $total, $timestamp)->sum()->max()->count();

        foreach ($parts as $part => $value) {
            $this->pulse->record("slow_client_request_{$part}", $key, $value, $timestamp)->sum()->max()->onlyBuckets();
        }

        if (is_string($user = $header['u'] ?? null) || is_int($user)) {
            $this->recordUser((string) $user, $method, $path, $total, $parts, $timestamp);
        }
    }

    /**
     * Record the request for the Slow User Requests and Application Usage cards.
     *
     * The slowest client total is recorded for every slow client request. The count only includes requests that were
     * slow for the client but not on the server: those that were slow on the server are already counted by the
     * SlowRequests recorder (`slow_user_request` / `slow_user_endpoint`), so nothing is counted twice.
     *
     * @param  array<string, int>  $parts
     */
    protected function recordUser(string $user, string $method, string $path, int $total, array $parts, int $timestamp): void
    {
        if ($user === '') {
            return;
        }

        $endpoint = json_encode([$user, $method, $path], flags: JSON_THROW_ON_ERROR);

        $this->pulse->record('slow_client_user_endpoint', $endpoint, $total, $timestamp)->max(); // with an entry, for the key

        $server = $parts['queue'] + $parts['db'] + $parts['http'] + $parts['app'];
        $serverCounted = Config::get('pulse.recorders.'.SlowRequests::class.'.enabled', true)
            && $server >= $this->threshold($path, SlowRequests::class);

        if (! $serverCounted) {
            $this->pulse->record('slow_client_user_request', $user, timestamp: $timestamp)->count(); // with an entry, for the key
            $this->pulse->record('slow_client_user_endpoint_only', $endpoint, timestamp: $timestamp)->count()->onlyBuckets();
        }
    }

    /**
     * Split the client total into whole-millisecond parts that add up to it exactly (bytes and queries are extra).
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $line
     * @return array<string, int>
     */
    protected function breakdown(int $total, float $requestStart, array $header, array $line): array
    {
        $int = fn ($value) => is_numeric($value) ? max(0, (int) round((float) $value)) : null;

        // upload: first request byte → FastCGI hand-off (nginx `$msec`, passed to PHP as PULSE_REQUEST_START)
        $upload = is_numeric($header['h'] ?? null) ? min($total, $int(((float) $header['h'] - $requestStart) * 1000)) : 0;

        // server: hand-off → response built (queue + PHP); without the header's PHP time (streamed / file responses,
        // Slow Requests recorder disabled), nginx's upstream time, which then includes the queue wait
        $php = $int($header['p'] ?? null);
        $queue = min($total - $upload, $php === null ? 0 : ($int($header['q'] ?? null) ?? 0));
        $php = min($total - $upload - $queue, $php ?? $this->upstreamMilliseconds($line['ut'] ?? null) ?? 0);
        $db = min($php, $int($header['d'] ?? null) ?? 0);
        $http = min($php - $db, $int($header['x'] ?? null) ?? 0);

        return [
            'upload' => $upload,
            'queue' => $queue,
            'db' => $db,
            'queries' => $int($header['n'] ?? null) ?? 0,
            'http' => $http,
            'app' => $php - $db - $http,
            'download' => $total - $upload - $queue - $php,
            'bytes' => $int($line['b'] ?? null) ?? 0,
        ];
    }

    /**
     * The upstream (PHP-FPM) time in milliseconds, e.g. `0.512`, `0.5, 0.3` (several attempts) or `-` (none).
     */
    protected function upstreamMilliseconds(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match_all('/\d+(?:\.\d+)?/', $value, $matches)) {
            return null;
        }

        return (int) round(array_sum(array_map('floatval', $matches[0])) * 1000);
    }

    /**
     * The UDP socket, opened on first use (null when listening failed).
     *
     * @return resource|null
     */
    protected function socket()
    {
        if ($this->socket === null) {
            $listen = Config::get('pulse.recorders.'.self::class.'.listen', 'udp://127.0.0.1:5140');
            $socket = @stream_socket_server($listen, $errorCode, $errorMessage, STREAM_SERVER_BIND);

            if ($socket === false) {
                $this->socket = false;

                throw new RuntimeException("Pulse client requests: could not listen on [{$listen}]: {$errorMessage}");
            }

            stream_set_blocking($socket, false);

            $this->socket = $socket;
        }

        return $this->socket ?: null;
    }
}
