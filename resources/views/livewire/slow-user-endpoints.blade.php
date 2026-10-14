<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Slow User Endpoints"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="{{ is_array($config['threshold']) ? '' : $config['threshold'].'ms threshold, ' }}past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
        <x-slot:actions>
            <x-pulse::select
                wire:model.live="orderBy"
                id="select-slow-user-endpoints-order-by"
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
        @if ($slowUserEndpoints->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="0%" />
                    <col width="0%" />
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>User</x-pulse::th>
                        <x-pulse::th>Method</x-pulse::th>
                        <x-pulse::th>Route</x-pulse::th>
                        <x-pulse::th class="text-right">Count</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($slowUserEndpoints->take(100) as $slowUserEndpoint)
                        <tr wire:key="{{ $slowUserEndpoint->user_key.$slowUserEndpoint->method.$slowUserEndpoint->uri }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $slowUserEndpoint->user_key.$slowUserEndpoint->method.$slowUserEndpoint->uri }}-row">
                            <x-pulse::td class="whitespace-nowrap">
                                <span class="text-xs text-gray-900 dark:text-gray-100 font-medium" title="{{ $slowUserEndpoint->user->extra ?? '' }}">
                                    {{ $slowUserEndpoint->user->name ?? $slowUserEndpoint->user_key }}
                                </span>
                            </x-pulse::td>
                            <x-pulse::td>
                                <x-pulse::http-method-badge :method="$slowUserEndpoint->method" />
                            </x-pulse::td>
                            <x-pulse::td class="overflow-hidden max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $slowUserEndpoint->uri }}">
                                    {{ $slowUserEndpoint->uri }}
                                </code>
                                @if (is_array($config['threshold']))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $slowUserEndpoint->threshold }}ms threshold
                                    </p>
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                @if ($config['sample_rate'] < 1)
                                    <span title="Sample rate: {{ $config['sample_rate'] }}, Raw value: {{ number_format($slowUserEndpoint->count) }}">~{{ number_format($slowUserEndpoint->count * (1 / $config['sample_rate'])) }}</span>
                                @else
                                    {{ number_format($slowUserEndpoint->count) }}
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($slowUserEndpoint->slowest === null)
                                    <strong>Unknown</strong>
                                @else
                                    <strong>{{ number_format($slowUserEndpoint->slowest) ?: '<1' }}</strong> ms
                                @endif
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            @if ($slowUserEndpoints->count() > 100)
                <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
            @endif
        @endif
    </x-pulse::scroll>
</x-pulse::card>
