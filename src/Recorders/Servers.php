<?php

namespace Laravel\Pulse\Recorders;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Pulse;
use RuntimeException;

/**
 * @internal
 */
class Servers
{
    use Concerns\Throttling;

    /**
     * Callback to detect CPU usage.
     *
     * @var null|(callable(): int)
     */
    protected static $detectCpuUsing;

    /**
     * Callback to detect memory.
     *
     * @var null|(callable(): array{total: int, used: int})
     */
    protected static $detectMemoryUsing;

    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = SharedBeat::class;

    /**
     * Fork: the previous `/proc/stat` CPU counters, for CPU usage over the sampling interval.
     *
     * @var array{total: int, idle: int, iowait: int, steal: int}|null
     */
    protected ?array $previousCpuTimes = null;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config
    ) {
        //
    }

    /**
     * Detect CPU via the given callback.
     *
     * @param  null|(callable(): int)  $callback
     */
    public static function detectCpuUsing(?callable $callback): void
    {
        self::$detectCpuUsing = $callback;
    }

    /**
     * Detect memory via the given callback.
     *
     * @param  null|(callable(): array{total: int, used: int})  $callback
     */
    public static function detectMemoryUsing(?callable $callback): void
    {
        self::$detectMemoryUsing = $callback;
    }

    /**
     * Record the system stats.
     */
    public function record(SharedBeat $event): void
    {
        $this->throttle(15, $event, function ($event) {
            $server = $this->config->get('pulse.recorders.'.self::class.'.server_name');
            $slug = Str::slug($server);

            ['total' => $memoryTotal, 'used' => $memoryUsed] = $this->memory();

            // Fork: Linux host and PHP-FPM metrics (null when unavailable)...
            $host = $this->hostStats();
            $cpu = $host['cpu'] ?? $this->cpu();

            $this->pulse->record('cpu', $slug, $cpu, $event->time)->avg()->max()->onlyBuckets();
            $this->pulse->record('memory', $slug, $memoryUsed, $event->time)->avg()->max()->onlyBuckets();

            $storage = collect($this->config->get('pulse.recorders.'.self::class.'.directories')) // @phpstan-ignore argument.templateType, argument.templateType
                ->filter(fn (string $directory) => ($this->pulse->rescue(fn () => disk_total_space($directory)) ?? false) !== false)
                ->map(fn (string $directory) => [
                    'directory' => $directory,
                    'total' => $total = intval(round(disk_total_space($directory) / 1024 / 1024)), // MB
                    'used' => intval(round($total - (disk_free_space($directory) / 1024 / 1024))), // MB
                ])
                ->values()
                ->all();

            $fpm = $host['fpm'] ?? [];

            foreach ([
                'server_load' => $host['load'] ?? null,
                'server_iowait' => $host['iowait'] ?? null,
                'fpm_workers' => $fpm === [] ? null : array_sum(array_column($fpm, 'workers')),
                'fpm_queue' => $fpm === [] ? null : array_sum(array_map(fn ($pool) => $pool['queue'] ?? 0, $fpm)),
            ] as $type => $value) {
                if ($value !== null) {
                    $this->pulse->record($type, $slug, $value, $event->time)->avg()->max()->onlyBuckets();
                }
            }

            $this->pulse->set('system', $slug, json_encode([
                'name' => $server,
                'cpu' => $cpu,
                'memory_used' => $memoryUsed,
                'memory_total' => $memoryTotal,
                'storage' => $storage,
                'cores' => $host['cores'] ?? null,
                'load' => $host['load'] ?? null,
                'iowait' => $host['iowait'] ?? null,
                'fpm' => $fpm,
            ], flags: JSON_THROW_ON_ERROR), $event->time);
        });
    }

    /**
     * Fork: Linux host metrics. Load and I/O wait are stored ×100 (0.01 precision).
     *
     * @return array{cores: ?int, load: ?int, cpu: ?int, iowait: ?int, fpm: list<array<string, mixed>>}|null
     */
    protected function hostStats(): ?array
    {
        if (! $this->isLinux() || $this->config->get('pulse.recorders.'.self::class.'.host_metrics', true) === false) {
            return null;
        }

        $stats = ['cores' => null, 'load' => null, 'cpu' => null, 'iowait' => null, 'fpm' => []];

        if (preg_match('/^([\d.]+)\s/', (string) $this->readSystemFile('/proc/loadavg'), $matches)) {
            $stats['load'] = (int) round((float) $matches[1] * 100);
        }

        $procStat = (string) $this->readSystemFile('/proc/stat');

        if (($cores = preg_match_all('/^cpu\d+\s/m', $procStat)) > 0) {
            $stats['cores'] = $cores;
        }

        if (preg_match('/^cpu\s+(.+)$/m', $procStat, $matches)) {
            // user nice system idle iowait irq softirq steal (guest time is already included in user)...
            $fields = array_map('intval', array_slice(preg_split('/\s+/', trim($matches[1])) ?: [], 0, 8)) + array_fill(0, 8, 0);
            $current = ['total' => array_sum($fields), 'idle' => $fields[3], 'iowait' => $fields[4], 'steal' => $fields[7]];

            if ($this->previousCpuTimes !== null && ($total = $current['total'] - $this->previousCpuTimes['total']) > 0) {
                $idle = $current['idle'] - $this->previousCpuTimes['idle'];
                $iowait = $current['iowait'] - $this->previousCpuTimes['iowait'];
                $steal = $current['steal'] - $this->previousCpuTimes['steal'];

                if (self::$detectCpuUsing === null) {
                    $stats['cpu'] = (int) round(max(0, $total - $idle - $iowait - $steal) / $total * 100);
                }

                $stats['iowait'] = (int) round(max(0, $iowait) / $total * 10_000);
            }

            $this->previousCpuTimes = $current;
        }

        $stats['fpm'] = $this->pulse->rescue(fn () => $this->fpmPools()) ?? [];

        return $stats;
    }

    /**
     * Fork: PHP-FPM pools of this PHP version (workers = pool processes, queue = listen socket Recv-Q).
     *
     * @return list<array{name: string, pm: ?string, max_children: ?int, listen: ?string, workers: int, queue: ?int}>
     */
    protected function fpmPools(): array
    {
        $config = $this->config->get('pulse.recorders.'.self::class.'.fpm', []);

        if ($config === false) {
            return [];
        }

        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $pools = [];

        foreach ($this->poolConfigFiles($config['pool_path'] ?? "/etc/php/{$version}/fpm/pool.d") as $contents) {
            $pool = null;

            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                    continue;
                }

                if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
                    $pool = trim($matches[1]);

                    if ($pool !== 'global') {
                        $pools[$pool] = ['name' => $pool, 'pm' => null, 'max_children' => null, 'listen' => null, 'workers' => 0, 'queue' => null];
                    }

                    continue;
                }

                if ($pool === null || $pool === 'global' || ! preg_match('/^([\w.]+)\s*=\s*(.*)$/', $line, $matches)) {
                    continue;
                }

                $value = trim(trim($matches[2]), '"\'');

                match ($matches[1]) {
                    'pm' => $pools[$pool]['pm'] = $value,
                    'pm.max_children' => $pools[$pool]['max_children'] = (int) $value,
                    'listen' => $pools[$pool]['listen'] = str_replace('$pool', $pool, $value),
                    default => null,
                };
            }
        }

        if ($pools === []) {
            return [];
        }

        $processes = $this->processList($config['process'] ?? "php-fpm{$version}");

        foreach ($pools as $name => $pool) {
            $pools[$name]['workers'] = preg_match_all('/^\s*php-fpm: pool '.preg_quote($name, '/').'\s*$/m', $processes);

            if ($pool['listen'] !== null) {
                $pools[$name]['queue'] = $this->listenQueue($pool['listen']);
            }
        }

        return array_values($pools);
    }

    /**
     * Fork: connections waiting to be accepted on the given FPM listen address (socket Recv-Q).
     */
    protected function listenQueue(string $listen): ?int
    {
        $unix = str_starts_with($listen, '/');
        $normalize = fn (string $address) => $unix ? (string) preg_replace('#^/var/run/#', '/run/', $address) : $address;
        $target = $normalize($listen);

        foreach (preg_split('/\R/', $this->listeningSockets($unix ? 'unix' : 'tcp')) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            foreach ($columns as $index => $column) {
                $matches = $unix
                    ? $normalize($column) === $target
                    : ($column === $target || (ctype_digit($target) && str_ends_with($column, ':'.$target)));

                if ($matches && $index >= 2 && is_numeric($columns[$index - 2])) {
                    return (int) $columns[$index - 2];
                }
            }
        }

        return null;
    }

    /**
     * Determine if the host metrics can be read (overridable for testing).
     */
    protected function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    /**
     * Read a system file (overridable for testing).
     */
    protected function readSystemFile(string $path): ?string
    {
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        return $contents === false ? null : $contents;
    }

    /**
     * The contents of the FPM pool configuration files (overridable for testing).
     *
     * @return list<string>
     */
    protected function poolConfigFiles(string $directory): array
    {
        return collect(glob(rtrim($directory, '/').'/*.conf') ?: [])
            ->map(fn (string $file) => $this->readSystemFile($file))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The command line of each process of the given executable (overridable for testing).
     */
    protected function processList(string $executable): string
    {
        return (string) shell_exec('ps -C '.escapeshellarg($executable).' -o args= 2>/dev/null');
    }

    /**
     * The listening sockets of the given type, without header (overridable for testing).
     */
    protected function listeningSockets(string $type): string
    {
        return (string) shell_exec(($type === 'unix' ? 'ss -xlnH' : 'ss -tlnH').' 2>/dev/null');
    }

    /**
     * CPU usage.
     */
    protected function cpu(): int
    {
        if (self::$detectCpuUsing) {
            return (self::$detectCpuUsing)();
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => (int) shell_exec("top -l 1 | grep -E \"^CPU\" | tail -1 | awk '{ print $3 + $5 }'"),
            'Linux' => (int) shell_exec("top -bn1 | grep -E '^(%Cpu|CPU)' | awk '{ print $2 + $4 }'"),
            'Windows' => (int) trim((string) shell_exec('wmic cpu get loadpercentage | more +1')),
            'BSD' => (int) shell_exec("top -b -d 2| grep 'CPU: ' | tail -1 | awk '{print$10}' | grep -Eo '[0-9]+\.[0-9]+' | awk '{ print 100 - $1 }'"),
            default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
        };
    }

    /**
     * Memory usage.
     *
     * @return array{total: int, used: int}
     */
    protected function memory(): array
    {
        if (self::$detectMemoryUsing) {
            return (self::$detectMemoryUsing)();
        }

        $memoryTotal = match (PHP_OS_FAMILY) {
            'Darwin' => intval(intval(shell_exec("sysctl hw.memsize | grep -Eo '[0-9]+'")) / 1024 / 1024),
            'Linux' => intval(intval(shell_exec("cat /proc/meminfo | grep MemTotal | grep -E -o '[0-9]+'")) / 1024),
            'Windows' => intval(((int) trim((string) shell_exec('wmic ComputerSystem get TotalPhysicalMemory | more +1'))) / 1024 / 1024),
            'BSD' => intval(intval(shell_exec("sysctl hw.physmem | grep -Eo '[0-9]+'")) / 1024 / 1024),
            default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
        };

        $memoryUsed = match (PHP_OS_FAMILY) {
            'Darwin' => $memoryTotal - intval(intval(shell_exec("vm_stat | grep 'Pages free' | grep -Eo '[0-9]+'")) * intval(shell_exec('pagesize')) / 1024 / 1024), // MB
            'Linux' => $memoryTotal - intval(intval(shell_exec("cat /proc/meminfo | grep MemAvailable | grep -E -o '[0-9]+'")) / 1024), // MB
            'Windows' => $memoryTotal - intval(((int) trim((string) shell_exec('wmic OS get FreePhysicalMemory | more +1'))) / 1024), // MB
            'BSD' => intval(intval(shell_exec("( sysctl vm.stats.vm.v_cache_count | grep -Eo '[0-9]+' ; sysctl vm.stats.vm.v_inactive_count | grep -Eo '[0-9]+' ; sysctl vm.stats.vm.v_active_count | grep -Eo '[0-9]+' ) | awk '{s+=$1} END {print s}'")) * intval(shell_exec('pagesize')) / 1024 / 1024), // MB
            default => throw new RuntimeException('The pulse:check command does not currently support '.PHP_OS_FAMILY),
        };

        return [
            'total' => $memoryTotal,
            'used' => $memoryUsed,
        ];
    }
}
