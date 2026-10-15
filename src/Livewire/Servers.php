<?php

namespace Laravel\Pulse\Livewire;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Illuminate\Support\InteractsWithTime;
use Livewire\Attributes\Lazy;
use Livewire\Livewire;

/**
 * @internal
 */
#[Lazy]
class Servers extends Card
{
    use InteractsWithTime;

    public int|string|null $ignoreAfter = null;

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$servers, $time, $runAt] = $this->remember(function () {
            $types = ['cpu', 'memory', 'server_load', 'server_iowait', 'server_steal', 'server_swap', 'server_storage', 'fpm_workers', 'fpm_queue'];
            $graphs = $this->graph($types, 'avg');
            $peaks = $this->graph($types, 'max');
            $bucketSeconds = (int) ($this->periodAsInterval()->totalSeconds / 60);

            return $this->values('system')
                ->map(function ($system, $slug) use ($graphs, $peaks, $bucketSeconds) {
                    if ($this->ignoreSystem($system)) {
                        return null;
                    }

                    $values = json_decode($system->value, flags: JSON_THROW_ON_ERROR);
                    $series = fn (string $type, $source) => $source->get($slug)?->get($type) ?? collect();
                    $storage = collect($values->storage); // @phpstan-ignore argument.templateType, argument.templateType
                    $fpm = collect($values->fpm ?? []);
                    $maxChildren = (int) $fpm->sum('max_children');

                    // Fork: chart series (avg + max per bucket) for each host metric, scaled for display...
                    $charts = collect([
                        'cpu' => ['type' => 'cpu', 'scale' => 1, 'y_max' => 100, 'current' => $values->cpu ?? null],
                        'memory' => ['type' => 'memory', 'scale' => 1, 'y_max' => (int) $values->memory_total, 'current' => $values->memory_used ?? null],
                        'load' => ['type' => 'server_load', 'scale' => 100, 'y_max' => null, 'current' => $values->load ?? null],
                        'iowait' => ['type' => 'server_iowait', 'scale' => 100, 'y_max' => null, 'current' => $values->iowait ?? null],
                        'steal' => ['type' => 'server_steal', 'scale' => 100, 'y_max' => null, 'current' => $values->steal ?? null],
                        'swap' => ['type' => 'server_swap', 'scale' => 1, 'y_max' => $values->swap_total ?? null, 'current' => $values->swap_used ?? null],
                        'storage' => ['type' => 'server_storage', 'scale' => 1, 'y_max' => $storage->first()?->total, 'current' => $storage->first()?->used],
                        'fpm_workers' => ['type' => 'fpm_workers', 'scale' => 1, 'y_max' => $maxChildren ?: null, 'current' => $fpm->isEmpty() ? null : $fpm->sum('workers')],
                        'fpm_queue' => ['type' => 'fpm_queue', 'scale' => 1, 'y_max' => null, 'current' => $fpm->isEmpty() ? null : $fpm->sum('queue')],
                    ])->map(function ($chart) use ($series, $graphs, $peaks) {
                        $scale = fn ($value) => $value === null ? null : round($value / $chart['scale'], 2);
                        $avg = $series($chart['type'], $graphs)->map($scale);
                        $max = $series($chart['type'], $peaks)->map($scale);
                        $peak = $max->filter(fn ($value) => $value !== null)->max() ?? $avg->filter(fn ($value) => $value !== null)->max();

                        return [
                            'avg' => $avg,
                            'max' => $max,
                            'current' => $scale($chart['current']),
                            'peak' => $peak,
                            'y_max' => max($chart['y_max'] ?? 0, $peak ?? 0, 1),
                            'has_data' => $chart['current'] !== null || $peak !== null,
                        ];
                    });

                    // Fork: buckets where the FPM pool was saturated (all workers busy or connections queued)...
                    $workersMax = $charts['fpm_workers']['max'];
                    $queueMax = $charts['fpm_queue']['max'];
                    $saturated = $workersMax->keys()
                        ->filter(fn ($bucket) => ($maxChildren > 0 && ($workersMax[$bucket] ?? 0) >= $maxChildren) || ($queueMax[$bucket] ?? 0) > 0)
                        ->values();

                    return (object) [
                        'name' => (string) $values->name,
                        'cpu_current' => (int) $values->cpu,
                        'cpu' => $graphs->get($slug)?->get('cpu') ?? collect(),
                        'memory_current' => (int) $values->memory_used,
                        'memory_total' => (int) $values->memory_total,
                        'memory' => $graphs->get($slug)?->get('memory') ?? collect(),
                        'storage' => $storage,
                        'cores' => $values->cores ?? null,
                        'fpm' => $fpm,
                        'fpm_max_children' => $maxChildren,
                        'charts' => $charts,
                        'saturation' => $saturated->isEmpty() ? null : [
                            'intervals' => $saturated->count(),
                            'from' => $saturated->first(),
                            'to' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $saturated->last(), 'UTC')->addSeconds($bucketSeconds)->toDateTimeString(),
                        ],
                        'updated_at' => $updatedAt = CarbonImmutable::createFromTimestamp($system->timestamp),
                        'recently_reported' => $updatedAt->isAfter(now()->subSeconds(30)),
                    ];
                })
                ->filter()
                ->sortBy('name');
        });

        if (Livewire::isLivewireRequest()) {
            $this->dispatch('servers-chart-update', servers: $servers);
        }

        return View::make('pulse::livewire.servers', [
            'servers' => $servers,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * Render the placeholder.
     */
    public function placeholder(): Renderable
    {
        return View::make('pulse::components.servers-placeholder', ['cols' => $this->cols, 'rows' => $this->rows, 'class' => $this->class]);
    }

    /**
     * Determine if the system should be ignored.
     *
     * @param  object{ timestamp: int, key: string, value: string }  $system
     */
    protected function ignoreSystem(object $system): bool
    {
        if ($this->ignoreAfter === null) {
            return false;
        }

        $ignoreAfter = is_numeric($this->ignoreAfter)
            ? (int) $this->ignoreAfter
            : CarbonInterval::createFromDateString($this->ignoreAfter)->totalSeconds;

        return CarbonImmutable::createFromTimestamp($system->timestamp)->addSeconds($ignoreAfter)->isPast();
    }
}
