<?php

namespace Laravel\Pulse\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\App;
use Laravel\Pulse\Support\CacheStoreResolver;

trait RemembersQueries
{
    /**
     * Remember the query for the current period.
     *
     * @return array{0: mixed, 1: float, 2: string}
     */
    protected function remember(callable $query, string $key = '', DateTimeInterface|DateInterval|Closure|int|null $ttl = 5): array
    {
        return App::make(CacheStoreResolver::class)->store()->remember('laravel:pulse:'.static::class.':'.$key.':'.$this->period, $ttl, function () use ($query) {
            $start = CarbonImmutable::now('UTC')->toDateTimeString();

            [$value, $duration] = method_exists(Benchmark::class, 'value')
                ? Benchmark::value(fn () => $query($this->periodAsInterval()))
                : $this->benchmarkValue(fn () => $query($this->periodAsInterval()));

            return [$value, $duration, $start];
        });
    }

    /**
     * Laravel 9 compatibility: copy of Laravel 10's Benchmark::value().
     *
     * @return array{0: mixed, 1: float}
     */
    protected function benchmarkValue(callable $callback): array
    {
        gc_collect_cycles();

        $start = hrtime(true);

        $result = $callback();

        return [$result, (hrtime(true) - $start) / 1000000];
    }
}
