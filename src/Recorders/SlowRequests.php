<?php

namespace Laravel\Pulse\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class SlowRequests
{
    use Concerns\Ignores,
        Concerns\LivewireRoutes,
        Concerns\Sampling,
        Concerns\Thresholds,
        ConfiguresAfterResolving;

    /**
     * Fork: per-request timing totals for the breakdown, collected until the response is ready and reset after each request.
     */
    protected float $dbMs = 0.0;

    protected int $queries = 0;

    protected float $httpMs = 0.0;

    protected int $httpInFlight = 0;

    protected ?float $httpSince = null;

    protected float $httpOverlapMs = 0.0;

    protected ?float $responseReadyAt = null;

    protected ?float $responseSentAt = null;

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
    public function register(callable $record, Application $app): void
    {
        $this->afterResolving(
            $app,
            Kernel::class,
            fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $record) // @phpstan-ignore method.notFound
        );

        // Fork: timing breakdown of slow requests (opt out with `breakdown` => false)...
        if (Config::get('pulse.recorders.'.self::class.'.breakdown', true)) {
            $this->afterResolving($app, 'events', fn (Dispatcher $events) => $events->listen(
                [QueryExecuted::class, RequestSending::class, ResponseReceived::class, ConnectionFailed::class, RequestHandled::class],
                fn ($event) => $this->pulse->rescue(fn () => $this->track($event)),
            ));

            // Response sent: the kernel's terminate phase starts after Response::send() (fastcgi_finish_request on PHP-FPM).
            // Registered at boot, so it runs before terminating callbacks registered later (application code, after-response
            // jobs); only callbacks registered earlier (the framework's view cache cleanup) and terminable middleware run first...
            $app->terminating(function () {
                if ($this->responseReadyAt !== null) {
                    $this->responseSentAt ??= microtime(true);
                }
            });
        }
    }

    /**
     * Fork: accumulate DB and outgoing HTTP time until the response is ready.
     */
    protected function track(object $event): void
    {
        if ($this->responseReadyAt !== null) {
            return;
        }

        $now = microtime(true);

        if ($event instanceof QueryExecuted) {
            $pulseConnection = Config::get('pulse.storage.database.connection');

            if ($pulseConnection === null || $event->connectionName !== $pulseConnection) {
                $this->dbMs += (float) $event->time;
                $this->queries++;

                // A query inside an open HTTP window (e.g. in an Http::pool callback) counts once, as DB...
                if ($this->httpInFlight > 0) {
                    $this->httpOverlapMs += (float) $event->time;
                }
            }
        } elseif ($event instanceof RequestSending) {
            // Overlapping calls (e.g. Http::pool) count once: time is accumulated while at least one call is in flight...
            if ($this->httpInFlight++ === 0) {
                $this->httpSince = $now;
            }
        } elseif ($event instanceof ResponseReceived || $event instanceof ConnectionFailed) {
            if ($this->httpInFlight > 0 && --$this->httpInFlight === 0) {
                $this->closeHttpWindow($now);
            }
        } elseif ($event instanceof RequestHandled) {
            $this->closeHttpWindow($now);
            $this->responseReadyAt = $now;
        }
    }

    /**
     * Fork: add the open in-flight HTTP window, minus DB time inside it, to the total.
     */
    protected function closeHttpWindow(float $now): void
    {
        if ($this->httpSince !== null) {
            $this->httpMs += max(0.0, ($now - $this->httpSince) * 1000 - $this->httpOverlapMs);
        }

        $this->httpSince = null;
        $this->httpInFlight = 0;
        $this->httpOverlapMs = 0.0;
    }

    /**
     * Fork: reset the per-request timing totals.
     */
    protected function resetTiming(): void
    {
        $this->dbMs = 0.0;
        $this->queries = 0;
        $this->httpMs = 0.0;
        $this->httpInFlight = 0;
        $this->httpSince = null;
        $this->httpOverlapMs = 0.0;
        $this->responseReadyAt = null;
        $this->responseSentAt = null;
    }

    /**
     * Record the request.
     */
    public function record(Carbon $startedAt, Request $request, Response $response): void
    {
        try {
            $this->recordRequest($startedAt, $request);
        } finally {
            $this->resetTiming(); // Fork: always start the next request with empty timing totals
        }
    }

    /**
     * Record the slow request (and, in the fork, its timing breakdown).
     */
    protected function recordRequest(Carbon $startedAt, Request $request): void
    {
        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        [$path, $via] = $this->resolveRoutePath($request);

        // Fork: with the breakdown, the duration is the full server-side time (web server hand-off or PHP start →
        // response sent, incl. PHP-FPM queue wait); without it, upstream's kernel lifecycle duration...
        $timing = $this->timing($startedAt, $request);
        $duration = $timing !== null ? $timing['total'] : (int) $startedAt->diffInMilliseconds();

        if (
            $this->shouldIgnore($path) ||
            $this->underThreshold($duration, $path)
        ) {
            return;
        }

        $this->pulse->record(
            type: 'slow_request',
            key: $key = json_encode([$request->method(), $path, $via], flags: JSON_THROW_ON_ERROR),
            value: $duration,
            timestamp: $startedAt,
        )->max()->count();

        if ($timing !== null) {
            $this->recordBreakdown($key, $timing, $startedAt);
        }

        if ($userId = $this->pulse->resolveAuthenticatedUserId()) {
            $this->pulse->record(
                type: 'slow_user_request',
                key: (string) $userId,
                timestamp: $startedAt,
            )->count();

            // Fork: per-user slow endpoints for the Slow User Endpoints card (opt out with `user_endpoints` => false)...
            if (Config::get('pulse.recorders.'.self::class.'.user_endpoints', true)) {
                $this->pulse->record(
                    type: 'slow_user_endpoint',
                    key: json_encode([(string) $userId, $request->method(), $path], flags: JSON_THROW_ON_ERROR),
                    value: $duration,
                    timestamp: $startedAt,
                )->max()->count();
            }
        }
    }

    /**
     * Fork: the request's timing so far, taken when the response has been built (for the client timing header).
     *
     * h = web server hand-off timestamp, q = queue wait (hand-off → PHP start), p = PHP start → now, d/n = DB time and
     * queries, x = outgoing HTTP time. DB and HTTP are null when the breakdown is disabled.
     *
     * @return array{h: ?float, q: ?int, p: int, d: ?int, n: ?int, x: ?int}
     */
    public function clientTiming(Request $request): array
    {
        $now = microtime(true);
        $phpStart = $request->server('REQUEST_TIME_FLOAT');
        $phpStart = is_numeric($phpStart) && (float) $phpStart <= $now && (float) $phpStart > $now - 3600 ? (float) $phpStart : $now;

        $handOff = $request->server(Config::get('pulse.recorders.'.self::class.'.queue_start_param', 'PULSE_REQUEST_START'));
        $handOff = is_numeric($handOff) && (float) $handOff <= $phpStart + 1 && (float) $handOff > $phpStart - 3600 ? min((float) $handOff, $phpStart) : null;

        $breakdown = (bool) Config::get('pulse.recorders.'.self::class.'.breakdown', true);
        $http = $this->httpMs + ($this->httpSince !== null ? max(0.0, ($now - $this->httpSince) * 1000 - $this->httpOverlapMs) : 0.0);

        return [
            'h' => $handOff,
            'q' => $handOff === null ? null : (int) round(($phpStart - $handOff) * 1000),
            'p' => (int) round(($now - $phpStart) * 1000),
            'd' => $breakdown ? (int) round($this->dbMs) : null,
            'n' => $breakdown ? $this->queries : null,
            'x' => $breakdown ? (int) round($http) : null,
        ];
    }

    /**
     * Fork: full timing of the request, in whole milliseconds (null without the breakdown, e.g. no RequestHandled).
     *
     * total = start → response sent, where start = web server hand-off (`PULSE_REQUEST_START`, e.g. nginx `$msec`)
     * when available, otherwise PHP's request start (`REQUEST_TIME_FLOAT`). Every millisecond belongs to exactly one part:
     * queue (hand-off → PHP start), DB, HTTP, and app + other (= the rest: PHP boot, framework, application code, sending).
     * Parts are rounded before the remainder is taken, so queue + DB + HTTP + app = total exactly.
     *
     * @return array{total: int, queue: ?int, db: int, queries: int, http: int, app: int}|null
     */
    protected function timing(Carbon $startedAt, Request $request): ?array
    {
        if ($this->responseReadyAt === null) {
            return null;
        }

        $kernelStart = (float) $startedAt->format('U.u');
        $phpStart = $request->server('REQUEST_TIME_FLOAT');
        $phpStart = is_numeric($phpStart) && (float) $phpStart <= $kernelStart && (float) $phpStart > $kernelStart - 3600 ? (float) $phpStart : $kernelStart;

        $handOff = $request->server(Config::get('pulse.recorders.'.self::class.'.queue_start_param', 'PULSE_REQUEST_START'));
        $handOff = is_numeric($handOff) && (float) $handOff <= $phpStart + 1 && (float) $handOff > $phpStart - 3600 ? min((float) $handOff, $phpStart) : null;

        $end = $this->responseSentAt ?? $this->responseReadyAt;
        $total = (int) round(max(0.0, ($end - ($handOff ?? $phpStart)) * 1000));
        $queue = $handOff === null ? null : min($total, (int) round(($phpStart - $handOff) * 1000));
        $db = min((int) round($this->dbMs), $total - ($queue ?? 0));
        $http = min((int) round($this->httpMs), $total - ($queue ?? 0) - $db);

        return [
            'total' => $total,
            'queue' => $queue,
            'db' => $db,
            'queries' => $this->queries,
            'http' => $http,
            'app' => $total - ($queue ?? 0) - $db - $http,
        ];
    }

    /**
     * Fork: record the timing breakdown of a slow request (aggregates only, same key as `slow_request`).
     *
     * All parts are summed over the same requests (queue counts 0 where the hand-off timestamp is missing, as the total
     * then starts at PHP start), so average total = average queue + DB + HTTP + app + other.
     *
     * @param  array{total: int, queue: ?int, db: int, queries: int, http: int, app: int}  $timing
     */
    protected function recordBreakdown(string $key, array $timing, Carbon $startedAt): void
    {
        foreach ([
            'slow_request_total' => $timing['total'],
            'slow_request_queue' => $timing['queue'] ?? 0,
            'slow_request_db' => $timing['db'],
            'slow_request_queries' => $timing['queries'],
            'slow_request_http' => $timing['http'],
            'slow_request_app' => $timing['app'],
        ] as $type => $value) {
            $entry = $this->pulse->record($type, $key, $value, $startedAt)->sum()->max();

            if ($type === 'slow_request_total') {
                $entry->count();
            }

            $entry->onlyBuckets();
        }

        if ($timing['queue'] !== null) {
            $this->pulse->record('slow_request_queue_measured', $key, 1, $startedAt)->count()->onlyBuckets();
        }
    }
}
