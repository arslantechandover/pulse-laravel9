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
            <div class="grid grid-cols-1 @lg:grid-cols-2 @3xl:grid-cols-3 @6xl:grid-cols-4 gap-2">
                @php
                    $sampleRate = (float) $config['sample_rate'];
                @endphp

                @foreach ($slowUserRequests as $slowUserRequest)
                    <x-pulse::user-card wire:key="{{ $slowUserRequest->key }}" :user="$slowUserRequest->user">
                        <x-slot:stats>
                            @if ($sampleRate < 1)
                                <span title="Sample rate: {{ $sampleRate }}, Raw value: {{ number_format($slowUserRequest->count) }}">~{{ number_format($slowUserRequest->count * (1 / $sampleRate)) }}</span>
                            @else
                                {{ number_format($slowUserRequest->count) }}
                            @endif
                        </x-slot:stats>
                    </x-pulse::user-card>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
