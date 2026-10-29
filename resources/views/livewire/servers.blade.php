@php
$friendlySize = function(int|float $mb, int $precision = 0) {
    if ($mb >= 1024 * 1024) {
        return round($mb / 1024 / 1024, $precision) . 'TB';
    }
    if ($mb >= 1024) {
        return round($mb / 1024, $precision) . 'GB';
    }
    return round($mb, $precision) . 'MB';
};

$cols = ! empty($cols) ? $cols : 'full';
$rows = ! empty($rows) ? $rows : 1;
@endphp

<section
    wire:poll.5s
    x-data="{
        loading: false,
        init() {
            Livewire.hook('commit', ({ component, succeed }) => {
                if (component.id === $wire.__instance.id) {
                    succeed(() => this.loading = false)
                }
            })
        },
    }"
    class="overflow-x-auto pb-px default:col-span-full default:lg:col-span-{{ $cols }} default:row-span-{{ $rows }} {{ $class }}"
    :class="loading && 'opacity-25 animate-pulse'"
>
    @if ($servers->isNotEmpty())
        <div class="grid grid-cols-[max-content,minmax(max-content,1fr),max-content,minmax(min-content,2fr),max-content,minmax(min-content,2fr),minmax(max-content,1fr)]">
            <div></div>
            <div></div>
            <div class="text-xs uppercase text-left text-gray-500 dark:text-gray-400 font-bold">CPU</div>
            <div></div>
            <div class="text-xs uppercase text-left text-gray-500 dark:text-gray-400 font-bold">Memory</div>
            <div></div>
            <div class="text-xs uppercase text-left text-gray-500 dark:text-gray-400 font-bold">Storage</div>
            @foreach ($servers as $slug => $server)
                @php
                    $charts = $server->charts;
                    $dim = ! $server->recently_reported ? 'opacity-25 animate-pulse' : '';
                    $pad = $servers->count() > 1 ? 'py-2' : '';
                @endphp
                <div wire:key="{{ $slug }}-indicator" class="flex items-center {{ $pad }}" title="{{ $server->updated_at->fromNow() }}">
                    @if ($server->recently_reported)
                        <div class="w-5 flex justify-center mr-1">
                            <div class="h-1 w-1 bg-green-500 rounded-full animate-pulse"></div>
                        </div>
                    @else
                        <x-pulse::icons.signal-slash class="w-5 h-5 stroke-red-500 mr-1" />
                    @endif
                </div>
                <div wire:key="{{ $slug }}-name" class="flex items-center pr-8 xl:pr-12 {{ $pad }} {{ $dim }}">
                    <x-pulse::icons.server class="w-6 h-6 mr-2 stroke-gray-500 dark:stroke-gray-400" />
                    <span class="text-base font-bold text-gray-600 dark:text-gray-300" x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`">{{ $server->name }}</span>
                </div>
                <div wire:key="{{ $slug }}-cpu" class="flex items-center {{ $pad }} {{ $dim }}">
                    <div class="flex flex-col">
                        <div class="text-xl font-bold text-gray-700 dark:text-gray-200 w-14 whitespace-nowrap tabular-nums">
                            {{ $server->cpu_current }}%
                        </div>
                        @if ($charts['cpu']['peak'] !== null)
                            <div class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap tabular-nums" title="Highest 15-second sample in the selected period">peak {{ round($charts['cpu']['peak']) }}%</div>
                        @endif
                    </div>
                </div>
                <div wire:key="{{ $slug }}-cpu-graph" class="flex items-center pr-8 xl:pr-12 {{ $pad }} {{ $dim }}">
                    <div
                        wire:ignore
                        class="w-full min-w-[5rem] max-w-xs h-9 relative"
                        x-data="serverMetricChart({
                            slug: '{{ $slug }}',
                            metric: 'cpu',
                            labels: @js($charts['cpu']['avg']->keys()),
                            avg: @js($charts['cpu']['avg']->values()),
                            max: @js($charts['cpu']['max']->values()),
                            yMax: 100,
                            unit: '%',
                            decimals: 1,
                        })"
                    >
                        <canvas x-ref="canvas" class="w-full ring-1 ring-gray-900/5 bg-white dark:bg-gray-900 rounded-md shadow-sm"></canvas>
                    </div>
                </div>
                <div wire:key="{{ $slug }}-memory" class="flex items-center {{ $pad }} {{ $dim }}">
                    <div class="w-36 flex-shrink-0 whitespace-nowrap tabular-nums">
                        <span class="text-xl font-bold text-gray-700 dark:text-gray-200">
                            {{ $friendlySize($server->memory_current, 1) }}
                        </span>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            / {{ $friendlySize($server->memory_total, 1) }}
                        </span>
                        @if ($charts['memory']['peak'] !== null)
                            <div class="text-xs text-gray-500 dark:text-gray-400" title="Highest 15-second sample in the selected period">peak {{ $friendlySize($charts['memory']['peak'], 1) }}</div>
                        @endif
                    </div>
                </div>
                <div wire:key="{{ $slug }}-memory-graph" class="flex items-center pr-8 xl:pr-12 {{ $pad }} {{ $dim }}">
                    <div
                        wire:ignore
                        class="w-full min-w-[5rem] max-w-xs h-9 relative"
                        x-data="serverMetricChart({
                            slug: '{{ $slug }}',
                            metric: 'memory',
                            labels: @js($charts['memory']['avg']->keys()),
                            avg: @js($charts['memory']['avg']->values()),
                            max: @js($charts['memory']['max']->values()),
                            yMax: @js($server->memory_total),
                            unit: '',
                            format: 'mb',
                        })"
                    >
                        <canvas x-ref="canvas" class="w-full ring-1 ring-gray-900/5 bg-white dark:bg-gray-900 rounded-md shadow-sm"></canvas>
                    </div>
                </div>
                <div wire:key="{{ $slug }}-storage" class="flex items-center gap-8 {{ $pad }} {{ $dim }}">
                    @foreach ($server->storage as $storage)
                        <div wire:key="{{ $slug.'-storage-'.$storage->directory }}" class="flex items-center gap-4" title="Directory: {{ $storage->directory }}">
                            <div class="whitespace-nowrap tabular-nums">
                                <span class="text-xl font-bold text-gray-700 dark:text-gray-200">{{ $friendlySize($storage->used) }}</span>
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">/ {{ $friendlySize($storage->total) }}</span>
                            </div>

                            <div
                                wire:ignore
                                x-data="storageChart({
                                    slug: '{{ $slug }}',
                                    directory: '{{ $storage->directory }}',
                                    used: {{ $storage->used }},
                                    total: {{ $storage->total }},
                                })"
                            >
                                <canvas x-ref="canvas" class="h-8 w-8"></canvas>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Fork: host & PHP-FPM health row --}}
                @php
                    $fpmPools = $server->fpm->pluck('name')->implode(', ');
                    $fpmTitle = $server->fpm->map(fn ($pool) => "pool {$pool->name}: pm={$pool->pm}, max_children={$pool->max_children}, listen={$pool->listen}")->implode('; ').'. Shared by every site using this pool.';
                    $metrics = collect([
                        'load' => ['label' => 'Load (1m)'.($server->cores ? ' / '.$server->cores.' cores' : ''), 'format' => fn ($v) => number_format($v, 2), 'unit' => '', 'decimals' => 2],
                        'iowait' => ['label' => 'IO wait', 'format' => fn ($v) => number_format($v, 1), 'unit' => '%'],
                        'fpm_workers' => ['label' => 'PHP-FPM workers'.($fpmPools !== '' ? ' · '.$fpmPools : ''), 'format' => fn ($v) => number_format($v), 'avg_format' => fn ($v) => number_format($v, 1), 'unit' => $server->fpm_max_children ? ' / '.$server->fpm_max_children : '', 'sample' => '1-second', 'emphasize_peak' => true, 'title' => 'Worker processes (idle + busy), sampled every second. Chart scale 0 to max_children. Purple line: average per interval; amber line: peak (highest 1-second reading per interval), so short bursts stay visible. '.$fpmTitle],
                        'fpm_queue' => ['label' => 'Listen queue', 'format' => fn ($v) => number_format($v), 'avg_format' => fn ($v) => number_format($v, 1), 'unit' => '', 'sample' => '1-second', 'title' => 'Requests waiting for a free PHP-FPM worker at the same moment, sampled every second. '.$fpmTitle],
                    ])->filter(fn ($metric, $key) => $charts[$key]['has_data']);
                @endphp
                @if ($metrics->isNotEmpty())
                    <div wire:key="{{ $slug }}-health" class="{{ $dim }}" style="grid-column: 1 / -1; contain: inline-size; min-width: 0; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(156, 163, 175, 0.2);">
                        <div class="flex flex-wrap" style="gap: 0.75rem 2.5rem;">
                            @foreach ($metrics as $key => $metric)
                                <div wire:key="{{ $slug }}-health-{{ $key }}" class="flex items-center gap-4" @isset($metric['title']) title="{{ $metric['title'] }}" @endisset>
                                    <div class="flex flex-col" style="min-width: 7.5rem;">
                                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400 font-bold whitespace-nowrap">{{ $metric['label'] }}</div>
                                        <div class="whitespace-nowrap tabular-nums">
                                            <span class="text-xl font-bold text-gray-700 dark:text-gray-200">{{ $charts[$key]['current'] === null ? '—' : ($metric['format'])($charts[$key]['current']) }}{{ $metric['unit'] === '%' ? '%' : '' }}</span>
                                            @if ($metric['unit'] !== '' && $metric['unit'] !== '%')
                                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metric['unit'] }}</span>
                                            @endif
                                        </div>
                                        @if ($charts[$key]['peak'] !== null)
                                            <div class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap tabular-nums" title="{{ isset($metric['avg_format']) ? 'Average and highest' : 'Highest' }} {{ $metric['sample'] ?? '15-second' }} sample in the selected period">
                                                @isset($metric['avg_format'])
                                                    avg {{ $charts[$key]['period_avg'] === null ? '—' : ($metric['avg_format'])($charts[$key]['period_avg']) }} &middot;
                                                @endisset
                                                peak {{ ($metric['format'])($charts[$key]['peak']) }}{{ $metric['unit'] === '%' ? '%' : '' }}
                                            </div>
                                        @endif
                                        @if ($key === 'fpm_queue')
                                            <div class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap tabular-nums" title="Seconds in the selected period in which at least one request was waiting for a worker (1-second samples)">queued {{ number_format($server->queued_seconds) }}s</div>
                                        @endif
                                    </div>
                                    <div
                                        wire:ignore
                                        class="h-9 relative"
                                        style="width: 9rem;"
                                        x-data="serverMetricChart({
                                            slug: '{{ $slug }}',
                                            metric: '{{ $key }}',
                                            labels: @js($charts[$key]['avg']->keys()),
                                            avg: @js($charts[$key]['avg']->values()),
                                            max: @js($charts[$key]['max']->values()),
                                            yMax: @js($charts[$key]['y_max']),
                                            unit: @js($metric['unit'] === '%' ? '%' : ''),
                                            decimals: @js($metric['decimals'] ?? 1),
                                            emphasizePeak: @js($metric['emphasize_peak'] ?? false),
                                        })"
                                    >
                                        <canvas x-ref="canvas" class="w-full ring-1 ring-gray-900/5 bg-white dark:bg-gray-900 rounded-md shadow-sm"></canvas>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if ($server->saturation)
                            <div wire:key="{{ $slug }}-saturation" class="mt-2 text-sm font-medium" style="color: #ef4444;">
                                &#9888; PHP-FPM saturated: all {{ $server->fpm_max_children }} workers busy in {{ $server->saturation['intervals'] }} {{ $server->saturation['intervals'] === 1 ? 'interval' : 'intervals' }}{{ $server->saturation['peak_queue'] > 0 ? ' (peak '.$server->saturation['peak_queue'].' waiting, queued '.$server->saturation['queued_seconds'].'s)' : '' }}:
                                <span x-text="formatDate('{{ $server->saturation['from'] }}')"></span> &ndash; <span x-text="formatDate('{{ $server->saturation['to'] }}')"></span>
                            </div>
                        @endif
                        @if ($server->queued)
                            <div wire:key="{{ $slug }}-queued" class="mt-2 text-sm font-medium" style="color: #f59e0b;">
                                &#9888; Requests waited for a PHP-FPM worker in {{ $server->queued['intervals'] }} {{ $server->queued['intervals'] === 1 ? 'interval' : 'intervals' }} while the pool was not full (peak {{ $server->queued['peak_queue'] }} waiting, queued {{ $server->queued['queued_seconds'] }}s, workers peaked at {{ $server->queued['peak_workers'] }}{{ $server->fpm_max_children ? '/'.$server->fpm_max_children : '' }}):
                                <span x-text="formatDate('{{ $server->queued['from'] }}')"></span> &ndash; <span x-text="formatDate('{{ $server->queued['to'] }}')"></span>
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</section>

@script
<script>
Alpine.data('serverMetricChart', (config) => ({
    init() {
        const dataset = (label, color, width, data) => ({
            label,
            borderColor: color,
            borderWidth: width,
            borderCapStyle: 'round',
            data,
            pointHitRadius: 10,
            pointStyle: false,
            tension: 0.2,
            spanGaps: false,
        })

        // Compact tooltip: short local time + one value line, pinned to the top so it fits the small canvas...
        const tooltipPlugin = Chart.registry?.getPlugin?.('tooltip')

        if (tooltipPlugin?.positioners && ! tooltipPlugin.positioners.pulseTop) {
            tooltipPlugin.positioners.pulseTop = (items) => items.length ? { x: items[0].element.x, y: 0 } : false
        }

        const shortTime = (value) => {
            const parts = String(value).match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/)

            return parts === null
                ? String(value)
                : new Date(Date.UTC(+parts[1], +parts[2] - 1, +parts[3], +parts[4], +parts[5], +parts[6]))
                    .toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        }

        const format = (value) => {
            if (value === null || value === undefined) {
                return '–'
            }

            if (config.format === 'mb') {
                return value >= 1024 ? `${(value / 1024).toFixed(1)} GB` : `${Math.round(value)} MB`
            }

            return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: config.decimals ?? 1 })}${config.unit ?? ''}`
        }

        let chart = new Chart(
            this.$refs.canvas,
            {
                type: 'line',
                data: {
                    labels: config.labels,
                    datasets: [
                        // Fork: a clearly visible peak line where short bursts matter (PHP-FPM workers)...
                        config.emphasizePeak ? dataset('Peak', '#f59e0b', 1.5, config.max) : dataset('Peak', '#c084fc80', 1, config.max),
                        dataset('Average', '#9333ea', 2, config.avg),
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        autoPadding: false,
                    },
                    scales: {
                        x: {
                            display: false,
                            grid: {
                                display: false,
                            },
                        },
                        y: {
                            display: false,
                            min: 0,
                            max: config.yMax,
                            grid: {
                                display: false,
                            },
                        },
                    },
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            mode: 'index',
                            position: tooltipPlugin?.positioners?.pulseTop ? 'pulseTop' : 'nearest',
                            yAlign: 'top',
                            intersect: false,
                            padding: 4,
                            caretSize: 0,
                            titleMarginBottom: 1,
                            titleFont: { size: 11, weight: 'bold' },
                            bodyFont: { size: 11 },
                            filter: (item) => item.datasetIndex === 1,
                            callbacks: {
                                title: (items) => items.length ? shortTime(items[0].label) : '',
                                label: (context) => `avg ${format(context.raw)} · peak ${format(context.chart.data.datasets[0].data[context.dataIndex])}`,
                            },
                            displayColors: false,
                        },
                    },
                },
            }
        )

        Livewire.on('servers-chart-update', ({ servers }) => {
            if (chart === undefined) {
                return
            }

            const series = servers[config.slug]?.charts?.[config.metric]

            if (series === undefined && chart) {
                chart.destroy()
                chart = undefined
                return
            }

            chart.data.labels = Object.keys(series.avg)
            chart.data.datasets[0].data = Object.values(series.max)
            chart.data.datasets[1].data = Object.values(series.avg)
            chart.options.scales.y.max = config.metric === 'cpu' ? 100 : series.y_max
            chart.update()
        })
    }
}))

Alpine.data('storageChart', (config) => ({
    init() {
        let chart = new Chart(
            this.$refs.canvas,
            {
                type: 'doughnut',
                data: {
                    labels: ['Used', 'Free'],
                    datasets: [
                        {
                            data: [
                                config.used,
                                config.total - config.used,
                            ],
                            backgroundColor: [
                                '#9333ea',
                                '#c084fc30',
                            ],
                            hoverBackgroundColor: [
                                '#9333ea',
                                '#c084fc30',
                            ],
                        },
                    ],
                },
                options: {
                    borderWidth: 0,
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            enabled: false,
                            callbacks: {
                                label: (context) => context.formattedValue + ' MB',
                            },
                            displayColors: false,
                        },
                    },
                },
            }
        )

        Livewire.on('servers-chart-update', ({ servers }) => {
            const storage = servers[config.slug]?.storage?.find(storage => storage.directory === config.directory)

            if (chart === undefined) {
                return
            }

            if (storage === undefined && chart) {
                chart.destroy()
                chart = undefined
                return
            }

            chart.data.datasets[0].data = [
                storage.used,
                storage.total - storage.used,
            ]
            chart.update()
        })
    }
}))
</script>
@endscript
