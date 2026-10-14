<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\Concerns\Thresholds;
use Laravel\Pulse\Recorders\SlowRequests;
use Livewire\Attributes\Lazy;

/**
 * @internal
 */
#[Lazy]
class SlowUserRequests extends Card
{
    use Thresholds;

    /**
     * The number of endpoints shown per user.
     */
    protected const ENDPOINTS_PER_USER = 5;

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowUserRequests, $time, $runAt] = $this->remember(function () {
            $counts = $this->aggregate('slow_user_request', 'count', limit: 10);

            $userKeys = $counts->pluck('key')->map(fn ($key) => (string) $key);

            $endpoints = $this->aggregate('slow_user_endpoint', ['max', 'count'], 'max', limit: 1000)
                ->map(function ($row) {
                    [$userKey, $method, $uri] = json_decode($row->key, flags: JSON_THROW_ON_ERROR);

                    return (object) [
                        'user_key' => (string) $userKey,
                        'method' => $method,
                        'uri' => $uri,
                        'count' => (int) $row->count,
                        'slowest' => $row->max,
                        'threshold' => $this->threshold($uri, SlowRequests::class),
                    ];
                })
                ->whereIn('user_key', $userKeys->all())
                ->groupBy('user_key');

            $users = Pulse::resolveUsers($counts->pluck('key'));

            return $counts->map(function ($row) use ($users, $endpoints) {
                $userEndpoints = $endpoints->get((string) $row->key, collect())->values();

                return (object) [
                    'key' => $row->key,
                    'user' => $users->find($row->key),
                    'count' => (int) $row->count,
                    'slowest' => $userEndpoints->max('slowest'),
                    'endpoints' => $userEndpoints,
                ];
            });
        });

        return View::make('pulse::livewire.slow-user-requests', [
            'time' => $time,
            'runAt' => $runAt,
            'slowUserRequests' => $slowUserRequests,
            'endpointsPerUser' => static::ENDPOINTS_PER_USER,
            'config' => [
                'threshold' => Config::get('pulse.recorders.'.SlowRequests::class.'.threshold'),
                'sample_rate' => Config::get('pulse.recorders.'.SlowRequests::class.'.sample_rate'),
            ],
        ]);
    }
}
