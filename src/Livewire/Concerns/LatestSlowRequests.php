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
