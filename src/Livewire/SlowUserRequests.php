<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\SlowRequests;
use Livewire\Attributes\Lazy;

/**
 * @internal
 */
#[Lazy]
class SlowUserRequests extends Card
{
    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowUserRequests, $time, $runAt] = $this->remember(function () {
            $counts = $this->aggregate('slow_user_request', 'count', limit: 10);

            $users = Pulse::resolveUsers($counts->pluck('key'));

            return $counts->map(fn ($row) => (object) [
                'key' => $row->key,
                'user' => $users->find($row->key),
                'count' => (int) $row->count,
            ]);
        });

        return View::make('pulse::livewire.slow-user-requests', [
            'time' => $time,
            'runAt' => $runAt,
            'slowUserRequests' => $slowUserRequests,
            'config' => [
                'threshold' => Config::get('pulse.recorders.'.SlowRequests::class.'.threshold'),
                'sample_rate' => Config::get('pulse.recorders.'.SlowRequests::class.'.sample_rate'),
            ],
        ]);
    }
}
