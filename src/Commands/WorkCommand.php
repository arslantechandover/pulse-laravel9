<?php

namespace Laravel\Pulse\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Support\CacheStoreResolver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'pulse:work')]
class WorkCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'pulse:work {--stop-when-empty : Stop when the stream is empty}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Process incoming Pulse data from the ingest stream';

    /**
     * Handle the command.
     */
    public function handle(
        Pulse $pulse,
        CacheStoreResolver $cache,
    ): int {
        $lastRestart = $cache->store()->get('laravel:pulse:restart');

        $lastTrimmedStorageAt = CarbonImmutable::now()->startOfMinute();

        while (true) {
            $now = CarbonImmutable::now();

            if ($lastRestart !== $cache->store()->get('laravel:pulse:restart')) {
                return self::SUCCESS;
            }

            $pulse->digest();

            if ($now->subMinutes(10)->greaterThan($lastTrimmedStorageAt)) {
                $pulse->trim();

                $lastTrimmedStorageAt = $now;
            }

            $this->ensureTelescopeEntriesAreCollected();

            if ($this->option('stop-when-empty')) {
                return self::SUCCESS;
            }

            if (class_exists(Sleep::class)) {
                Sleep::for(1)->second();
            } else {
                // Laravel 9 compatibility: Sleep was added in Laravel 10...
                sleep(1);
            }
        }
    }

    /**
     * Schedule Telescope to store entries if enabled.
     */
    protected function ensureTelescopeEntriesAreCollected(): void
    {
        if ($this->laravel->bound(\Laravel\Telescope\Contracts\EntriesRepository::class)) {
            \Laravel\Telescope\Telescope::store($this->laravel->make(\Laravel\Telescope\Contracts\EntriesRepository::class));
        }
    }
}
