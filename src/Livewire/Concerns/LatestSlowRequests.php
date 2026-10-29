<?php

namespace Laravel\Pulse\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Recorders\ClientRequests;

/**
 * Fork: the most recent slow request per key in the selected period, from the per-request entries already stored
 * by the SlowRequests recorder (server time) and the ClientRequests recorder (client total, incl. download).
 *
 * @internal
 */
trait LatestSlowRequests
{
    /**
     * The latest slow request per key: duration, timestamp and whether the duration is the client total or server time.
     *
     * A client entry and a server entry less than 2 seconds apart belong to the same request: the client total is used.
     *
     * @return \Illuminate\Support\Collection<string, array{duration: int, timestamp: int, source: 'client'|'server'}>
     */
    protected function latestSlowRequests(string $serverType, string $clientType): Collection
    {
        $types = Config::get('pulse.recorders.'.ClientRequests::class.'.enabled') ? [$serverType, $clientType] : [$serverType];
        $since = CarbonImmutable::now()->subSeconds((int) $this->periodAsInterval()->totalSeconds)->getTimestamp();
        $latest = [];

        foreach (DB::connection(Config::get('pulse.storage.database.connection'))
            ->table('pulse_entries')
            ->select(['type', 'key', 'timestamp', 'value'])
            ->whereIn('type', $types)
            ->where('timestamp', '>=', $since)
            ->whereNotNull('value')
            ->orderBy('timestamp')
            ->cursor() as $entry) {
            $latest[$entry->type === $serverType ? 'server' : 'client'][$entry->key] = ['duration' => (int) $entry->value, 'timestamp' => (int) $entry->timestamp];
        }

        $server = collect($latest['server'] ?? []);
        $client = collect($latest['client'] ?? []);

        return $server->keys()->merge($client->keys())->unique()->mapWithKeys(function ($key) use ($server, $client) {
            [$s, $c] = [$server->get($key), $client->get($key)];

            return [$key => $c !== null && ($s === null || $c['timestamp'] >= $s['timestamp'] - 2)
                ? $c + ['source' => 'client']
                : $s + ['source' => 'server']];
        });
    }

    /**
     * The newest entry per key of one type in the selected period (Slow Queries, Slow Jobs, Slow Outgoing Requests).
     *
     * @return \Illuminate\Support\Collection<string, array{duration: int, timestamp: int, source: string, label: string}>
     */
    protected function latestEntries(string $type, string $label): Collection
    {
        $latest = [];

        foreach (DB::connection(Config::get('pulse.storage.database.connection'))
            ->table('pulse_entries')
            ->select(['key', 'timestamp', 'value'])
            ->where('type', $type)
            ->where('timestamp', '>=', CarbonImmutable::now()->subSeconds((int) $this->periodAsInterval()->totalSeconds)->getTimestamp())
            ->whereNotNull('value')
            ->orderBy('timestamp')
            ->cursor() as $entry) {
            $latest[$entry->key] = ['duration' => (int) $entry->value, 'timestamp' => (int) $entry->timestamp, 'source' => 'entry', 'label' => $label];
        }

        return collect($latest);
    }

    /**
     * The per-request breakdowns of slow client requests in the selected period (empty without client timing).
     *
     * @return \Illuminate\Support\Collection<int, array{endpoint: string, method: string, path: string, user: ?string, total: int, timestamp: int, parts: array<string, int>}>
     */
    protected function clientRequestDetails(): Collection
    {
        if (! Config::get('pulse.recorders.'.ClientRequests::class.'.enabled')) {
            return collect();
        }

        return DB::connection(Config::get('pulse.storage.database.connection'))
            ->table('pulse_entries')
            ->select(['key', 'timestamp', 'value'])
            ->where('type', 'slow_client_request_detail')
            ->where('timestamp', '>=', CarbonImmutable::now()->subSeconds((int) $this->periodAsInterval()->totalSeconds)->getTimestamp())
            ->orderBy('timestamp')
            ->get()
            ->map(function ($entry) {
                $detail = json_decode($entry->key, true);

                if (! is_array($detail['k'] ?? null) || count($detail['k']) !== 3 || ! is_array($detail['p'] ?? null)) {
                    return null;
                }

                return [
                    'endpoint' => json_encode($detail['k']),
                    'method' => (string) $detail['k'][0],
                    'path' => (string) $detail['k'][1],
                    'user' => isset($detail['u']) ? (string) $detail['u'] : null,
                    'total' => (int) $entry->value,
                    'timestamp' => (int) $entry->timestamp,
                    'parts' => $detail['p'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The slowest and the latest request detail per key.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $details
     * @param  callable(array<string, mixed>): ?string  $key
     * @return array{0: \Illuminate\Support\Collection<string, array<string, mixed>>, 1: \Illuminate\Support\Collection<string, array<string, mixed>>}
     */
    protected function slowestAndLatestDetails(Collection $details, callable $key): array
    {
        [$slowest, $latest] = [[], []];

        foreach ($details as $detail) {
            if (($k = $key($detail)) === null) {
                continue;
            }

            if (! isset($slowest[$k]) || $detail['total'] > $slowest[$k]['total']) {
                $slowest[$k] = $detail;
            }

            if (! isset($latest[$k]) || $detail['timestamp'] >= $latest[$k]['timestamp']) {
                $latest[$k] = $detail;
            }
        }

        return [collect($slowest), collect($latest)];
    }

    /**
     * Attach the breakdown of the same request to a latest slow request (same timestamp and duration).
     *
     * @param  array<string, mixed>|null  $latest
     * @param  array<string, mixed>|null  $detail
     * @return array<string, mixed>|null
     */
    protected function withLatestBreakdown(?array $latest, ?array $detail): ?array
    {
        if ($latest !== null && $detail !== null && $latest['timestamp'] === $detail['timestamp'] && $latest['duration'] === $detail['total']) {
            $latest['breakdown'] = static::clientBreakdownText($detail['parts'], $detail['total']);
        }

        return $latest;
    }

    /**
     * "Queue … + DB … (… queries) + HTTP … + App … + Download …" (Upload first, only when it took time).
     *
     * @param  array<string, int|float|null>  $parts
     */
    public static function clientPartsText(array $parts): string
    {
        $ms = fn ($value) => number_format((float) ($value ?? 0)).' ms';

        return implode(' + ', array_filter([
            ($parts['upload'] ?? 0) > 0 ? 'Upload '.$ms($parts['upload']) : null,
            'Queue '.$ms($parts['queue'] ?? 0),
            'DB '.$ms($parts['db'] ?? 0).' ('.number_format((float) ($parts['queries'] ?? 0)).' queries)',
            'HTTP '.$ms($parts['http'] ?? 0),
            'App '.$ms($parts['app'] ?? 0),
            'Download '.$ms($parts['download'] ?? 0),
        ]));
    }

    /**
     * "Queue … + DB … + HTTP … + App … + Download … = Total …".
     *
     * @param  array<string, int|float|null>  $parts
     */
    public static function clientBreakdownText(array $parts, int|float $total): string
    {
        return static::clientPartsText($parts).' = Total '.number_format((float) $total).' ms';
    }

    /**
     * Whole-millisecond average parts that add up to the rounded average total (download is the remainder).
     *
     * @param  array<string, int|float|null>  $averages
     * @return array{0: array<string, int>, 1: int}
     */
    protected static function roundedAverageParts(array $averages, float $total): array
    {
        $total = (int) round($total);
        $parts = [];

        foreach (['upload', 'queue', 'db', 'http', 'app'] as $part) {
            $parts[$part] = (int) round((float) ($averages[$part] ?? 0));
        }

        $parts['download'] = max(0, $total - array_sum($parts));
        $parts['queries'] = (int) round((float) ($averages['queries'] ?? 0));

        return [$parts, $parts['download'] === 0 ? array_sum(array_diff_key($parts, ['queries' => 0])) : $total];
    }

    /**
     * The latest slow request per user, from latest slow requests keyed by `[user, method, path]`.
     *
     * @param  \Illuminate\Support\Collection<string, array{duration: int, timestamp: int, source: string}>  $endpoints
     * @return \Illuminate\Support\Collection<string, array{duration: int, timestamp: int, source: string}>
     */
    protected function latestByUser(Collection $endpoints): Collection
    {
        return $endpoints
            ->map(fn ($latest, $key) => $latest + ['user' => (string) (json_decode($key, true)[0] ?? '')])
            ->filter(fn ($latest) => $latest['user'] !== '')
            ->groupBy('user')
            ->map(fn ($latest) => collect($latest)->sortByDesc('timestamp')->first());
    }
}
