@props(['latest'])
{{-- Fork: the most recent slow request in the period - its own duration, how long ago it started and (when known) its breakdown --}}
@if ($latest)
    <p {{ $attributes->merge(['class' => 'mt-1 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap']) }}
        x-data
        x-bind:title="'Latest slow request: ' + formatDate('{{ gmdate('Y-m-d H:i:s', $latest['timestamp']) }}') + ' - {{ e($latest['breakdown'] ?? ($latest['source'] === 'client' ? 'Total (client request time, incl. download)' : 'server time')) }}. Only requests at or above the threshold are recorded.'"
    >
        latest {{ number_format($latest['duration']) }} ms &middot; {{ \Carbon\CarbonImmutable::createFromTimestamp($latest['timestamp'])->diffForHumans() }}
    </p>
@endif
