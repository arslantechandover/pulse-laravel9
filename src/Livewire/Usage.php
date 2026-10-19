<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\ClientRequests;
use Laravel\Pulse\Recorders\SlowRequests;
use Laravel\Pulse\Recorders\UserJobs;
use Laravel\Pulse\Recorders\UserRequests;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * @internal
 */
#[Lazy]
class Usage extends Card
{
    /**
     * The type of usage to show.
     *
     * @var 'requests'|'slow_requests'|'jobs'|null
     */
    public ?string $type = null;

    /**
     * The usage type.
     *
     * @var 'requests'|'slow_requests'|'jobs'
     */
    #[Url]
    public string $usage = 'requests';

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        $type = $this->type ?? $this->usage;

        [$userRequestCounts, $time, $runAt] = $this->remember(
            function () use ($type) {
                // Fork: with client timing enabled, slow endpoints also count requests that were slow only for the
                // client (see ClientRequests::recordUser(); requests slow on the server are not counted twice)...
                $counts = $type === 'slow_requests' && Config::get('pulse.recorders.'.ClientRequests::class.'.enabled')
                    ? $this->aggregateTypes(['slow_user_request', 'slow_client_user_request'], 'count', limit: 1000)
                        ->map(fn ($row) => (object) ['key' => $row->key, 'count' => (int) ($row->slow_user_request ?? 0) + (int) ($row->slow_client_user_request ?? 0)])
                        ->filter(fn ($row) => $row->key !== null && $row->count > 0)
                        ->sortByDesc('count')
                        ->take(10)
                        ->values()
                    : $this->aggregate(
                        match ($type) {
                            'requests' => 'user_request',
                            'slow_requests' => 'slow_user_request',
                            'jobs' => 'user_job',
                        },
                        'count',
                        limit: 10,
                    );

                $users = Pulse::resolveUsers($counts->pluck('key'));

                return $counts->map(fn ($row) => (object) [
                    'key' => $row->key,
                    'user' => $users->find($row->key),
                    'count' => (int) $row->count,
                ]);
            },
            $type
        );

        return View::make('pulse::livewire.usage', [
            'time' => $time,
            'runAt' => $runAt,
            'userRequestsConfig' => Config::get('pulse.recorders.'.UserRequests::class),
            'slowRequestsConfig' => Config::get('pulse.recorders.'.SlowRequests::class),
            'jobsConfig' => Config::get('pulse.recorders.'.UserJobs::class),
            'userRequestCounts' => $userRequestCounts,
        ]);
    }
}
