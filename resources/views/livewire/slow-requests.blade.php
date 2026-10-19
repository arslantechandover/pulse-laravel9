<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Slow Requests"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="{{ is_array($config['threshold']) ? '' : $config['threshold'].'ms threshold, ' }}past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            <x-pulse::select
                wire:model.live="orderBy"
                id="select-slow-requests-order-by"
                label="Sort by"
                :options="[
                    'slowest' => 'slowest',
                    'count' => 'count',
                ]"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($slowRequests->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="0%" />
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Method</x-pulse::th>
                        <x-pulse::th>Route</x-pulse::th>
                        <x-pulse::th class="text-right">Count</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($slowRequests->take(100) as $slowRequest)
                        <tr wire:key="{{ $slowRequest->method.$slowRequest->uri }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $slowRequest->method.$slowRequest->uri }}-row">
                            <x-pulse::td>
                                <x-pulse::http-method-badge :method="$slowRequest->method" />
                            </x-pulse::td>
                            <x-pulse::td class="overflow-hidden max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $slowRequest->uri }}">
                                    {{ $slowRequest->uri }}
                                </code>
                                @if ($slowRequest->action)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $slowRequest->action }}">
                                        {{ $slowRequest->action }}
                                    </p>
                                @endif
                                @if ($breakdown = $slowRequest->breakdown)
                                    @php
                                        $ms = fn ($value) => $value === null ? 'n/a' : number_format($value).' ms';
                                        $queueMeasured = $breakdown['queue_measured'];
                                        $queue = $queueMeasured === 0 ? 'n/a' : $ms($breakdown['queue']['avg']).($queueMeasured < $breakdown['requests'] ? '*' : '');
                                        $title = 'Average per slow request ('.number_format($breakdown['requests']).' with a breakdown): total = queue wait + DB + HTTP + app + other, from the web server hand-off (or PHP start) until the response was sent. '
                                            .'Max: total '.$ms($breakdown['total']['max']).', queue wait '.($queueMeasured === 0 ? 'n/a' : $ms($breakdown['queue']['max'])).', DB '.$ms($breakdown['db']['max']).' ('.number_format($breakdown['queries']['max'] ?? 0).' queries), HTTP '.$ms($breakdown['http']['max']).', app + other '.$ms($breakdown['app']['max']).'. '
                                            .($queueMeasured < $breakdown['requests'] ? 'Queue wait measured for '.number_format($queueMeasured).' of '.number_format($breakdown['requests']).' requests (needs the web server timestamp PULSE_REQUEST_START); the others start at PHP start.' : 'Queue wait measured for all requests.');
                                    @endphp
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $title }}">
                                        avg total {{ $ms($breakdown['total']['avg']) }} = queue {{ $queue }} + DB {{ $ms($breakdown['db']['avg']) }} ({{ number_format($breakdown['queries']['avg'] ?? 0) }} queries) + HTTP {{ $ms($breakdown['http']['avg']) }} + app + other {{ $ms($breakdown['app']['avg']) }}
                                    </p>
                                @endif
                                @if ($client = $slowRequest->client)
                                    @php
                                        $clientMs = fn ($value) => $value === null ? 'n/a' : number_format($value).' ms';
                                        $clientSize = fn ($bytes) => $bytes === null ? 'n/a' : ($bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024).' KB');
                                        $clientTitle = 'Client time, measured by the web server: first request byte received → last response byte sent. upload = receiving the request; queue = waiting for a PHP-FPM worker; DB, HTTP (outgoing calls) and app = server processing until the response was built; download = handing the response over and sending it to the client. Excludes DNS/TCP/TLS connection set-up and app-side parsing. '
                                            .'Average of '.number_format($client['requests']).' request(s) at or above '.(is_array($config['client_threshold']) ? 'the configured' : $config['client_threshold'].'ms').' threshold. '
                                            .'Max: client '.$clientMs($client['total']['max']).', upload '.$clientMs($client['upload']['max']).', queue '.$clientMs($client['queue']['max']).', DB '.$clientMs($client['db']['max']).' ('.number_format($client['queries']['max'] ?? 0).' queries), HTTP '.$clientMs($client['http']['max']).', app '.$clientMs($client['app']['max']).', download '.$clientMs($client['download']['max']).', response '.$clientSize($client['bytes']['max']).'.';
                                    @endphp
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $clientTitle }}">
                                        client {{ $clientMs($client['total']['avg']) }} = upload {{ $clientMs($client['upload']['avg']) }} + queue {{ $clientMs($client['queue']['avg']) }} + DB {{ $clientMs($client['db']['avg']) }} ({{ number_format($client['queries']['avg'] ?? 0) }} queries) + HTTP {{ $clientMs($client['http']['avg']) }} + app {{ $clientMs($client['app']['avg']) }} + download {{ $clientMs($client['download']['avg']) }} · {{ $clientSize($client['bytes']['avg']) }} ({{ number_format($client['requests']) }}×)
                                    </p>
                                @endif
                                @if (is_array($config['threshold']))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $slowRequest->threshold }}ms threshold
                                    </p>
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                @if ($slowRequest->count === null)
                                    <span title="Not slow on the server; see the client line">&mdash;</span>
                                @elseif ($config['sample_rate'] < 1)
                                    <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($slowRequest->count) }}">~{{ number_format($slowRequest->count * (1 / $config['sample_rate'])) }}</span>
                                @else
                                    {{ number_format($slowRequest->count) }}
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if (($slowRequest->client['total']['max'] ?? null) !== null)
                                    <span title="Slowest client time (web server: first request byte received → last response byte sent, incl. download){{ $slowRequest->slowest === null ? '; not slow on the server' : '; slowest server time '.number_format($slowRequest->slowest).' ms (may be a different request)' }}">
                                        <strong>{{ number_format($slowRequest->client['total']['max']) }}</strong> ms
                                    </span>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        server {!! $slowRequest->slowest === null ? '&mdash;' : e(number_format($slowRequest->slowest)).' ms' !!}
                                    </p>
                                @elseif ($slowRequest->slowest === null)
                                    <strong>Unknown</strong>
                                @else
                                    <strong>{{ number_format($slowRequest->slowest) ?: '<1' }}</strong> ms
                                @endif
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            @if ($slowRequests->count() > 100)
                <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
            @endif
        @endif
    </x-pulse::scroll>
</x-pulse::card>
