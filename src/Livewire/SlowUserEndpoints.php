<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Concerns\Thresholds;
use Laravel\Pulse\Recorders\SlowRequests as SlowRequestsRecorder;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * @internal
 */
#[Lazy]
class SlowUserEndpoints extends Card
{
    use Thresholds;

    /**
     * Ordering.
     *
     * @var 'slowest'|'count'
     */
    #[Url(as: 'slow-user-endpoints')]
    public string $orderBy = 'slowest';

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowUserEndpoints, $time, $runAt] = $this->remember(
            function () {
                $rows = $this->aggregate(
                    'slow_user_endpoint',
                    ['max', 'count'],
                    match ($this->orderBy) {
                        'count' => 'count',
                        default => 'max',
                    },
                )->map(function ($row) {
                    [$userKey, $method, $uri] = json_decode($row->key, flags: JSON_THROW_ON_ERROR);

                    return (object) [
                        'user_key' => $userKey,
                        'method' => $method,
                        'uri' => $uri,
                        'count' => $row->count,
                        'slowest' => $row->max,
                        'threshold' => $this->threshold($uri, SlowRequestsRecorder::class),
                    ];
                });

                $users = Pulse::resolveUsers($rows->pluck('user_key')->unique()->values());

                return $rows->map(function ($row) use ($users) {
                    $row->user = $users->find($row->user_key);

                    return $row;
                });
            },
            $this->orderBy,
        );

        return View::make('pulse::livewire.slow-user-endpoints', [
            'time' => $time,
            'runAt' => $runAt,
            'slowUserEndpoints' => $slowUserEndpoints,
            'config' => [
                'threshold' => Config::get('pulse.recorders.'.SlowRequestsRecorder::class.'.threshold'),
                'sample_rate' => Config::get('pulse.recorders.'.SlowRequestsRecorder::class.'.sample_rate'),
            ],
        ]);
    }
}
