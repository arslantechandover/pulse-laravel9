<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Slow User Requests"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="{{ is_array($config['threshold']) ? '' : $config['threshold'].'ms threshold, ' }}past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
        <x-slot:actions>
            @if (is_array($config['threshold']))
                @php
                    $message = 'You have per-route thresholds configured.';
                @endphp
                <button title="{{ $message }}" @click="alert(@js($message))">
                    <x-pulse::icons.information-circle class="w-5 h-5 stroke-gray-400 dark:stroke-gray-600" />
                </button>
            @endif
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($slowUserRequests->isEmpty())
            <x-pulse::no-results />
        @else
            @php
                $sampleRate = (float) $config['sample_rate'];
                $scaled = fn ($count) => $sampleRate < 1 ? '~'.number_format($count * (1 / $sampleRate)) : number_format($count);
            @endphp

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
                        <x-pulse::th>User / Route</x-pulse::th>
                        <x-pulse::th class="text-right">Count</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($slowUserRequests as $slowUserRequest)
                        <tr wire:key="{{ $slowUserRequest->key }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $slowUserRequest->key }}-row">
                            <x-pulse::td colspan="2" class="overflow-hidden max-w-[1px]">
                                <div class="text-sm text-gray-900 dark:text-gray-100 font-medium truncate" title="{{ $slowUserRequest->user->name ?? $slowUserRequest->key }}">
                                    {{ $slowUserRequest->user->name ?? $slowUserRequest->key }}
                                </div>
                                @if ($slowUserRequest->endpoints->isEmpty())
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        No endpoint details recorded for these requests
                                    </p>
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                @if ($sampleRate < 1)
                                    <span title="Sample rate: {{ $sampleRate }}, Raw value: {{ number_format($slowUserRequest->count) }}">{{ $scaled($slowUserRequest->count) }}</span>
                                @else
                                    {{ $scaled($slowUserRequest->count) }}
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if (($slowUserRequest->client_slowest ?? null) !== null)
                                    <span title="Slowest client time (web server: first request byte received → last response byte sent, incl. download){{ $slowUserRequest->slowest === null ? '; not slow on the server' : '; slowest server time '.number_format($slowUserRequest->slowest).' ms (may be a different request)' }}">
                                        <strong>{{ number_format($slowUserRequest->client_slowest) }}</strong> ms
                                    </span>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        server {!! $slowUserRequest->slowest === null ? '&mdash;' : e(number_format($slowUserRequest->slowest)).' ms' !!}
                                    </p>
                                @elseif ($slowUserRequest->slowest === null)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">&mdash;</span>
                                @else
                                    <strong>{{ number_format($slowUserRequest->slowest) ?: '<1' }}</strong> ms
                                @endif
                                <x-pulse::latest-slow-request :latest="$slowUserRequest->latest ?? null" />
                            </x-pulse::td>
                        </tr>
                        @foreach ($slowUserRequest->endpoints->take($endpointsPerUser) as $endpoint)
                            <tr wire:key="{{ $slowUserRequest->key.$endpoint->method.$endpoint->uri }}-row">
                                <x-pulse::td>
                                    <x-pulse::http-method-badge :method="$endpoint->method" />
                                </x-pulse::td>
                                <x-pulse::td class="overflow-hidden max-w-[1px]">
                                    <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $endpoint->uri }}">
                                        {{ $endpoint->uri }}
                                    </code>
                                    @if (is_array($config['threshold']))
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $endpoint->threshold }}ms threshold
                                        </p>
                                    @endif
                                </x-pulse::td>
                                <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                    {{ $scaled($endpoint->count) }}
                                </x-pulse::td>
                                <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                    @if (($endpoint->client_slowest ?? null) !== null)
                                        <span title="Slowest client time (incl. download){{ $endpoint->slowest === null ? '; not slow on the server' : '; slowest server time '.number_format($endpoint->slowest).' ms' }}">
                                            <strong>{{ number_format($endpoint->client_slowest) }}</strong> ms
                                        </span>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                            server {!! $endpoint->slowest === null ? '&mdash;' : e(number_format($endpoint->slowest)).' ms' !!}
                                        </p>
                                    @elseif ($endpoint->slowest === null)
                                        <strong>Unknown</strong>
                                    @else
                                        <strong>{{ number_format($endpoint->slowest) ?: '<1' }}</strong> ms
                                    @endif
                                    <x-pulse::latest-slow-request :latest="$endpoint->latest ?? null" />
                                </x-pulse::td>
                            </tr>
                        @endforeach
                        @if ($slowUserRequest->endpoints->count() > $endpointsPerUser)
                            <tr wire:key="{{ $slowUserRequest->key }}-more">
                                <x-pulse::td colspan="4" class="text-xs text-gray-500 dark:text-gray-400">
                                    + {{ $slowUserRequest->endpoints->count() - $endpointsPerUser }} more endpoints
                                </x-pulse::td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
