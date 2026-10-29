<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Recorders\Concerns\Thresholds;
use Laravel\Pulse\Recorders\SlowJobs as SlowJobsRecorder;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * @internal
 */
#[Lazy]
class SlowJobs extends Card
{
    use Concerns\LatestSlowRequests, Thresholds;

    /**
     * Ordering.
     *
     * @var 'slowest'|'count'
     */
    #[Url(as: 'slow-jobs')]
    public string $orderBy = 'slowest';

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowJobs, $time, $runAt] = $this->remember(
            function () {
                // Fork: the latest slow run per job (from the stored per-job entries)...
                $latest = $this->latestEntries('slow_job', 'job');

                return $this->aggregate(
                    'slow_job',
                    ['max', 'count'],
                    match ($this->orderBy) {
                        'count' => 'count',
                        default => 'max',
                    },
                )->map(fn ($row) => (object) [
                    'job' => $row->key,
                    'slowest' => $row->max,
                    'count' => $row->count,
                    'threshold' => $this->threshold($row->key, SlowJobsRecorder::class),
                    'latest' => $latest->get($row->key),
                ]);
            },
            $this->orderBy,
        );

        return View::make('pulse::livewire.slow-jobs', [
            'time' => $time,
            'runAt' => $runAt,
            'config' => Config::get('pulse.recorders.'.SlowJobsRecorder::class),
            'slowJobs' => $slowJobs,
        ]);
    }
}
