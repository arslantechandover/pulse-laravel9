@props(['latest'])
{{-- Fork: the most recent slow item in the period - its own duration, how long ago it started and (when known) its breakdown --}}
@if ($latest)
    @php
        $what = isset($latest['label']) ? 'Latest slow '.$latest['label'] : 'Latest slow request';
        $only = isset($latest['label']) ? 'Only '.\Illuminate\Support\Str::plural($latest['label']) : 'Only requests';
        $detail = $latest['breakdown'] ?? match ($latest['source']) {
            'client' => 'Total (client request time, incl. download)',
            'server' => 'server time',
            default => 'duration '.number_format($latest['duration']).' ms',
        };
    @endphp
    <p {{ $attributes->merge(['class' => 'mt-1 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap']) }}
        x-data
        x-bind:title="'{{ e($what) }}: ' + formatDate('{{ gmdate('Y-m-d H:i:s', $latest['timestamp']) }}') + ' - {{ e($detail) }}. {{ e($only) }} at or above the threshold are recorded.'"
    >
        latest {{ number_format($latest['duration']) }} ms &middot; {{ \Carbon\CarbonImmutable::createFromTimestamp($latest['timestamp'])->diffForHumans() }}
    </p>
@endif
