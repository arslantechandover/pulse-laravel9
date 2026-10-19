@props(['latest'])
{{-- Fork: the most recent slow request in the period - its own duration and how long ago it started --}}
@if ($latest)
    <p {{ $attributes->merge(['class' => 'mt-1 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap']) }}
        x-data
        x-bind:title="'Latest slow request: ' + formatDate('{{ gmdate('Y-m-d H:i:s', $latest['timestamp']) }}') + ' - {{ $latest['source'] === 'client' ? 'client total, incl. download' : 'server time' }}. Only requests at or above the threshold are recorded.'"
    >
        latest {{ number_format($latest['duration']) }} ms &middot; {{ \Carbon\CarbonImmutable::createFromTimestamp($latest['timestamp'])->diffForHumans() }}
    </p>
@endif
