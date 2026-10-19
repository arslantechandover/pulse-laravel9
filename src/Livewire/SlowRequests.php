<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Recorders\ClientRequests as ClientRequestsRecorder;
use Laravel\Pulse\Recorders\Concerns\Thresholds;
use Laravel\Pulse\Recorders\SlowRequests as SlowRequestsRecorder;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * @internal
 */
#[Lazy]
class SlowRequests extends Card
{
    use Concerns\LatestSlowRequests, Thresholds;

    /**
     * Ordering.
     *
     * @var 'slowest'|'count'
     */
    #[Url(as: 'slow-requests')]
    public string $orderBy = 'slowest';

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowRequests, $time, $runAt] = $this->remember(
            function () {
                // Fork: timing breakdown totals per endpoint (sums, maxima and the number of requests with a breakdown)...
                $parts = ['slow_request_total', 'slow_request_queue', 'slow_request_db', 'slow_request_queries', 'slow_request_http', 'slow_request_app'];
                $sums = $this->aggregateTypes($parts, 'sum', limit: 1000)->keyBy('key');
                $maxima = $this->aggregateTypes($parts, 'max', limit: 1000)->keyBy('key');
                $counts = $this->aggregateTypes(['slow_request_total', 'slow_request_queue_measured'], 'count', limit: 1000)->keyBy('key');

                $rows = $this->aggregate(
                    'slow_request',
                    ['max', 'count'],
                    match ($this->orderBy) {
                        'count' => 'count',
                        default => 'max',
                    },
                )->keyBy('key');

                // Fork: client-perceived time measured by the web server (only when the client requests recorder is enabled)...
                $client = $this->clientRequests();

                // Fork: the latest slow request per endpoint (from the stored per-request entries)...
                $latest = $this->latestSlowRequests('slow_request', 'slow_client_request');

                return $rows->keys()->merge($client->keys())->unique()->map(function ($key) use ($rows, $client, $sums, $maxima, $counts, $latest) {
                    [$method, $uri, $action] = json_decode($key, flags: JSON_THROW_ON_ERROR);
                    $row = $rows->get($key);

                    return (object) [
                        'uri' => $uri,
                        'method' => $method,
                        'action' => $action,
                        'count' => $row?->count,
                        'slowest' => $row?->max,
                        'threshold' => $this->threshold($uri, SlowRequestsRecorder::class),
                        'breakdown' => $this->breakdown($sums->get($key), $maxima->get($key), $counts->get($key)),
                        'client' => $client->get($key),
                        'latest' => $latest->get($key),
                    ];
                })->sortByDesc(fn ($row) => match ($this->orderBy) {
                    'count' => [$row->count ?? 0, $row->client['requests'] ?? 0],
                    default => max($row->slowest ?? 0, $row->client['total']['max'] ?? 0),
                })->values();
            },
            $this->orderBy,
        );

        return View::make('pulse::livewire.slow-requests', [
            'time' => $time,
            'runAt' => $runAt,
            'slowRequests' => $slowRequests,
            'config' => [
                'threshold' => Config::get('pulse.recorders.'.SlowRequestsRecorder::class.'.threshold'),
                'sample_rate' => Config::get('pulse.recorders.'.SlowRequestsRecorder::class.'.sample_rate'),
                'client_threshold' => Config::get('pulse.recorders.'.ClientRequestsRecorder::class.'.threshold'),
            ],
        ]);
    }

    /**
     * Fork: client-perceived time per endpoint (empty unless the client requests recorder is enabled).
     *
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    protected function clientRequests(): Collection
    {
        if (! Config::get('pulse.recorders.'.ClientRequestsRecorder::class.'.enabled')) {
            return collect();
        }

        $types = ['slow_client_request', ...array_map(fn ($part) => "slow_client_request_{$part}", ClientRequestsRecorder::PARTS)];
        $sums = $this->aggregateTypes($types, 'sum', limit: 1000)->keyBy('key');
        $maxima = $this->aggregateTypes($types, 'max', limit: 1000)->keyBy('key');
        $counts = $this->aggregateTypes(['slow_client_request'], 'count', limit: 1000)->keyBy('key');

        // Rows without a resolvable key (v1.4.7.9 recorded only buckets for endpoints slow only for the client) are skipped...
        return $counts->filter(fn ($row, $key) => $key !== '' && ($row->slow_client_request ?? 0) > 0)->map(function ($row, $key) use ($sums, $maxima) {
            $requests = (int) $row->slow_client_request;
            $part = fn (string $type) => [
                'avg' => isset($sums->get($key)->{$type}) ? round($sums->get($key)->{$type} / $requests, 1) : null,
                'max' => isset($maxima->get($key)->{$type}) ? (float) $maxima->get($key)->{$type} : null,
            ];

            return [
                'requests' => $requests,
                'total' => $part('slow_client_request'),
                ...collect(ClientRequestsRecorder::PARTS)->mapWithKeys(fn ($name) => [$name => $part("slow_client_request_{$name}")])->all(),
            ];
        });
    }

    /**
     * Fork: average and maximum of each breakdown part for one endpoint (null when nothing was recorded).
     *
     * Every part is averaged over the same requests, so avg total = queue + DB + HTTP + app + other.
     *
     * @return array<string, mixed>|null
     */
    protected function breakdown(?object $sums, ?object $maxima, ?object $counts): ?array
    {
        $requests = (int) ($counts->slow_request_total ?? 0);

        if ($sums === null || $requests === 0) {
            return null;
        }

        $part = fn (string $type) => [
            'avg' => isset($sums->{$type}) ? round($sums->{$type} / $requests, 1) : null,
            'max' => isset($maxima->{$type}) ? (float) $maxima->{$type} : null,
        ];

        return [
            'requests' => $requests,
            'queue_measured' => (int) ($counts->slow_request_queue_measured ?? 0),
            'total' => $part('slow_request_total'),
            'queue' => $part('slow_request_queue'),
            'db' => $part('slow_request_db'),
            'queries' => $part('slow_request_queries'),
            'http' => $part('slow_request_http'),
            'app' => $part('slow_request_app'),
        ];
    }
}
