<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
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
    use Thresholds;

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

                return $this->aggregate(
                    'slow_request',
                    ['max', 'count'],
                    match ($this->orderBy) {
                        'count' => 'count',
                        default => 'max',
                    },
                )->map(function ($row) use ($sums, $maxima, $counts) {
                    [$method, $uri, $action] = json_decode($row->key, flags: JSON_THROW_ON_ERROR);

                    return (object) [
                        'uri' => $uri,
                        'method' => $method,
                        'action' => $action,
                        'count' => $row->count,
                        'slowest' => $row->max,
                        'threshold' => $this->threshold($uri, SlowRequestsRecorder::class),
                        'breakdown' => $this->breakdown($sums->get($row->key), $maxima->get($row->key), $counts->get($row->key)),
                    ];
                });
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
            ],
        ]);
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
