# Release Notes

## Unreleased (laravel9-support fork, based on v1.4.7)

Laravel 9.52 compatibility. On Laravel 10+ each change defers to the original upstream code path.

### Added

* `Laravel\Pulse\Support\Laravel9HttpFactory`: Laravel 9-only `Http\Client\Factory` subclass providing Laravel 10's `globalMiddleware()`. Bound by `SlowOutgoingRequests` only when the method is missing and no other `Factory` binding exists.
* `Livewire\SlowUserRequests` card (`pulse.slow-user-requests`, view `livewire/slow-user-requests`), added to the default dashboard after Slow Requests. Shows the top 10 users by `slow_user_request` count with each user's slowest duration, and under each user their slow endpoints (method, route, count, slowest; top 5 by slowest, then "+ N more endpoints") from `slow_user_endpoint`. Users whose requests predate endpoint recording show "No endpoint details recorded for these requests". Counts are scaled by the `SlowRequests` recorder's sample rate; the header shows its threshold (per-route thresholds shown per endpoint). The existing Application Usage card with `type="slow_requests"` reads these from its `usage` dropdown/URL value instead of `type`, so it scales by the user-requests sample rate when the rates differ; this card avoids that. Fork-only feature, not Laravel 9-specific.
* History: v1.4.7.2 introduced the card with user totals only; v1.4.7.3 showed endpoint details in a separate `pulse.slow-user-endpoints` card, which is removed again in favour of showing them inside the Slow User Requests card.
* `Recorders\Servers` host and PHP-FPM metrics (Linux; `pulse:check` daemon only, every 15 seconds, aggregates only): `server_load` (1-minute load ×100), `server_iowait` (% ×100), `fpm_workers` (worker processes of this PHP version's FPM pools, counted from `ps`) and `fpm_queue` (listen-socket Recv-Q from `ss`), each with `avg` + `max`, plus `fpm_queue_seconds` (`count` of samples with a non-empty queue). Since v1.4.7.7 the two FPM metrics are sampled on every `pulse:check` beat (~1 second: one `ps` + one `ss` call), kept in memory and written with the 15-second batch as one entry per sample, so `avg`/`max` reflect 1-second resolution while database writes stay at one batch per 15 seconds; pool configuration is re-read once per 15-second window; pending samples are capped at 120. Load, I/O wait, CPU and memory stay at 15 seconds. Pools, `pm`, `pm.max_children` and `listen` are read from `/etc/php/{major.minor}/fpm/pool.d/*.conf` (`$pool` substituted; unix sockets incl. `/var/run` ↔ `/run`, and TCP `host:port` / port). The `system` snapshot also stores cores, load, I/O wait and the pools. Optional recorder config: `'host_metrics' => false` disables all of it; `'fpm' => false` or `'fpm' => ['pool_path' => …, 'process' => …]`. Unreadable sources are skipped. Fork-only feature. (v1.4.7.5 also recorded `server_steal`, `server_swap` and `server_storage`; removed in v1.4.7.6 as low-value — steal and swap stayed at 0 on the target host and disk usage barely changes within the 7-day retention; the storage donut still shows current usage. Existing rows of those types expire with normal trimming.)
* Servers card: second row per server with Load (vs cores), IO wait, PHP-FPM workers (`n / max_children`, pool names) and Listen queue — current value, period peak and an avg + max sparkline each; CPU and Memory also show the period peak and a max line. PHP-FPM workers and Listen queue also show the period average (`avg · peak`), and Listen queue shows `queued Ns` (seconds with at least one request waiting). Two warnings (since v1.4.7.7; previously one warning for "all workers busy or requests queued"): red "PHP-FPM saturated: all N workers busy" for intervals where the worker count reached `pm.max_children` (with peak queue and queued seconds), and amber "Requests waited for a PHP-FPM worker … while the pool was not full" for intervals with queued requests but fewer than `pm.max_children` workers (with peak queue, queued seconds and peak workers); an interval that is saturated is only reported in red. Rows only appear for metrics that have data, so snapshots from older versions render as before. The row is excluded from the grid's width calculation (`contain: inline-size`), so it wraps inside the card instead of widening it (v1.4.7.5 showed a horizontal scrollbar and stretched the first row). Chart tooltips show a short local time and one `avg · peak` line pinned to the top of the chart (v1.4.7.5's three-line tooltip with a full date was clipped by the 36px canvas). Fork-only feature.

### Changed

* `Recorders\SlowRequests`: for authenticated slow requests, additionally records `slow_user_endpoint` (key `[user, method, route]`, value duration, `max` + `count`) for the endpoint details in the Slow User Requests card. Adds one entry per authenticated slow request plus aggregate rows per distinct user/route; opt out with `'user_endpoints' => false` in the recorder's config (default `true`). Existing `slow_request` / `slow_user_request` recording unchanged. Fork-only feature.
* `Recorders\Servers`: `cpu` and `memory` are recorded with `max` in addition to `avg`, so short spikes survive in the 6h/24h/7d views. On Linux, CPU % is now computed from `/proc/stat` over the 15-second sampling interval (user + nice + system + irq + softirq; excludes idle, I/O wait and steal) instead of a single `top -bn1` frame (user + system); the first sample after the daemon starts, `detectCpuUsing()` and non-Linux hosts keep the upstream method. Fork-only change.
* `composer.json`: widened all 13 `illuminate/*` constraints to `^9.52|^10.48.4|^11.0.8|^12.0`.
* `composer.json`: removed `conflict: nunomaduro/collision <7.7.0`, which blocks Laravel 9 apps (Collision 6.x; Collision 7.7+ requires PHPUnit 10.1.2+ and targets Laravel 10). Upstream added it (commit `86e6bfb`) because Collision < 7.7's console exception-handler wrapper lacks `reportable()`; that case is now handled in `Recorders\Exceptions`.
* `Recorders\Exceptions`: when the resolved exception handler lacks `reportable()` (Collision < 7.7 console wrapper, active when running in console outside unit tests), registers the callback on the wrapped application handler instead. Upstream path unchanged otherwise. Without this, every Artisan command fails on Laravel 9 with Pulse enabled.
* Views (`components/pulse`, `components/select`, `livewire/cache`, `livewire/queues`, `livewire/slow-outgoing-requests`): replaced the Blade `@use(...)` directive (Laravel 10+) with an equivalent `@php use ...; @endphp`. On Laravel 9 `@use` is not compiled, so it was printed as text and the import was skipped, causing `Class "Str" not found` when cards rendered (unless the app happened to register a global `Str` alias).
* `Users`: when the user provider is not Eloquent, unknown user IDs (`retrieveById()` returns `null`) are dropped so they display as `ID: {key}`, instead of failing with `Users::key(): Argument #1 ($user) must be of type Authenticatable, null given`. Not Laravel 9-specific; surfaced in an app whose default `web` guard uses the `database` user provider while requests are authenticated through other guards.
* `Commands\ClearCommand`: on Laravel < 10 reports its default name as `pulse:clear` only. Laravel 9 does not split Symfony's pipe-joined `pulse:clear|pulse:purge` name, which broke `artisan list` and the command itself. Laravel 10+ keeps the upstream name and alias.
* `CheckCommand` / `WorkCommand`: fall back to `usleep()` / `sleep(1)` when `Illuminate\Support\Sleep` (Laravel 10+) is unavailable; sleep-until and sleep-for semantics preserved.
* `RemembersQueries`: falls back to a copy of Laravel 10's `Benchmark::value()` when unavailable.
* `PulseServiceProvider`: `about` shows plain `ENABLED`/`OFF` on Laravel 9 (`AboutCommand::format()` is Laravel 10+). No console styling; `about --json` returns a string instead of a boolean.
* `Recorders\Queues`: Laravel 9's `JobQueued` has no payload, so a random UUID seeds sampling for queued events. Identical at the default sample rate of 1; below 1, queued events are sampled independently of the job's later lifecycle events.
* `Recorders\SlowOutgoingRequests`: registers via the compat factory on Laravel 9. If the app binds its own `Factory`, outgoing requests are not monitored (no exception).

### Known limitations

* Raw Guzzle clients and third-party SDKs are not monitored (same as upstream).
* An `Http` client resolved before Pulse boots is not monitored.
* `pulse:purge` (legacy alias of `pulse:clear`) is not available on Laravel 9; use `pulse:clear`.
* User-based cards resolve user IDs through the application's default guard. Apps that authenticate through other guards (e.g. Passport API guards) record those guards' IDs, which may then display as `ID: {key}` or match an unrelated user; configure `Pulse::users()` in the app to resolve them correctly.
* Host / FPM metrics: FPM worker counts include idle spare workers (with `pm = dynamic` the count reaches `pm.max_children` only under load; with `pm = static` it is always the maximum, so use the queue or FPM's status page instead). The listen queue and worker count are 1-second samples (15-second before v1.4.7.7), so sub-second bursts can still be missed; `queued Ns` counts samples, i.e. approximately seconds. The queue value is the number of requests waiting at the same moment, not the number of requests that waited or how long each waited (per-request wait needs an arrival timestamp from the web server). Connections rejected because the backlog is full never appear in the queue (see the web server's error log). Only pools of the PHP version running `pulse:check` are read. Exact busy/idle counts and FPM's "max children reached" counter would need `pm.status_path`, which is not used.

### Validation performed

* `php -l` on all changed PHP files; `composer validate --no-check-publish --no-check-lock`.
* 48 isolated checks against Laravel 9.52.20 / PHP 8.1.12 covering every fallback: Http fake/middleware/retry/pool/failure/timing parity with a plain Laravel 9 Factory, queue events, benchmark, about, and command loop timing. The queue Laravel 10 path was simulated with Laravel 10.48.4's `JobQueued`.
* Collision conflict removal: a Composer `--dry-run` against a scratch copy of the backend's `composer.json`/`composer.lock` and a scratch copy of this fork without the conflict resolved with 3 lock installs, 0 updates and 0 removals (`doctrine/sql-formatter`, `laravel/pulse`, `livewire/livewire`). That dry run used `--ignore-platform-req=ext-sodium` because the local PHP lacks the sodium extension that the backend's existing lockfile already requires; it bypasses Composer's check and does not enable the extension.
* Collision handling: installing this fork in a Laravel 9.52.20 app with Collision 6.4.0 reproduced the upstream `Call to undefined method …ExceptionHandler::reportable()` failure in `php artisan package:discover`. An isolated check then confirmed the fallback records console exceptions through the Collision 6.4.0 wrapper while the direct path still records exceptions on a plain handler.
* `ClearCommand`: in a Laravel 9.52.20 app, `php artisan list` failed with `The "pulse:clear|pulse:purge" command cannot be found because it is registered under multiple names`; after the change `artisan list` and `pulse:clear --help` succeed and `pulse:purge` reports `Command "pulse:purge" is not defined` (expected on Laravel 9).
* Views: in a Laravel 9.52.20 app the dashboard printed `@use(...)` literally and lazy cards failed with `Class "Str" not found`; after the change all five views compile on Laravel 9's Blade compiler to `<?php use ...; ?>` (PHP lint clean).
* `Users`: with a `database` user provider, an unknown ID now resolves to `ID: {key}` instead of the `Users::key()` TypeError.
* Backend integration test (disposable Laravel 9.52.20 copy with this fork installed as a copied path repository, PHP 8.1.12, MySQL 8.0.30, Livewire fork 3.8.10): `package:discover`, `artisan list`, `about`, `pulse:check --once`, `pulse:work --stop-when-empty`, `pulse:restart` and `pulse:clear --help` succeed; every recorder wrote to a dedicated metrics database (requests, user requests, exceptions incl. a console-reported one, slow queries, outgoing requests, queued/processed/failed and slow jobs, cache, servers).
* Browser test (headless Chrome via DevTools protocol): the dashboard loads, all lazy cards load with HTTP 200 Livewire updates, recorded data is visible on every card, no JavaScript or console errors, polling sends updates, the period buttons update the URL (`?period=6_hours`) and `?period=7_days` deep links set every component's period.
* `SlowUserRequests` card (Laravel 9.52.20 app, PHP 8.1.12, files overlaid on the v1.4.7.1 install, read-only metrics database): component registered, rendered with `lazy: false` for the 7-day period; counts match `Pulse::aggregate('slow_user_request', 'count', …)`; with sample rates slow 0.5 / user 0.1 the card shows the correctly scaled `~18` for 9 raw (the Usage card with `type="slow_requests"` shows `~90`); the dashboard page lists the card; existing cards, routes and API responses unchanged. Not yet browser-tested.
* `SlowUserEndpoints` / `slow_user_endpoint` (same app, files overlaid on the v1.4.7.2 install, disposable metrics database, threshold 0): 6 requests through the HTTP kernel (client ×3, provider ×1, guest ×1, client ×1 with `user_endpoints` false) recorded `slow_request` +6, `slow_user_request` +5, `slow_user_endpoint` +4 with keys `[user, method, route]` and `max`/`count` aggregates; the (v1.4.7.3) separate card rendered `Client #991 · GET /api/v1/health · 3 · 45 ms` and the provider row; Slow Requests and Slow User Requests cards, dashboard, routes and API responses unchanged.
* Endpoint details inside `SlowUserRequests` (same app, files overlaid on the v1.4.7.3 install, disposable metrics database, threshold 0): after recording client/provider/guest requests on two routes, the card rendered each user's total and slowest (`Client #991 14 251 ms`, `Provider #7 4 64 ms`) followed by matching endpoint rows (`GET /api/v1/health 10 251 ms`, `GET / 2 67 ms`, `GET /api/v1/health 4 64 ms`), guests excluded; a user with only pre-existing data showed the "No endpoint details" note; with slow sample rate 0.5 all counts were scaled (`~28`, `~20`, `~4`, `~8`); `pulse.slow-user-endpoints` no longer registered and absent from the dashboard; all CSS classes in the view already exist in the compiled `dist/pulse.css`; routes and API responses unchanged. Not yet browser-tested.
* Host / FPM metrics and Servers card (same app, files overlaid on the v1.4.7.4 install, disposable metrics database; 32/32 checks): the recorder was fed the verbatim output of the target server's audit (Ubuntu 22.04, 6 cores, `/proc/stat`, `/proc/meminfo`, `/proc/loadavg`, `www.conf` with `pm = dynamic`, `pm.max_children = 20`, `ps` and `ss -xl` output) and parsed cores 6, load 0.20, swap 1023/0 MB and pool `www` (dynamic, 20, `/run/php/php8.1-fpm.sock`, 3 workers, queue 0); CPU over an interval gave busy 20%, I/O wait 1.00%, steal 0.50% for known counter deltas; edge cases (`[global]`, `$pool`, `/var/run` ↔ `/run`, TCP host:port and port-only, missing files, `fpm => false`, `host_metrics => false`, non-Linux) handled without errors. A simulated 40-minute run with an 8-minute incident (CPU 95%, load 6.40, 20/20 workers, queue 14, 3.4 GB) stored aggregates only with the expected maxima; the 24h graph kept max 95 while the avg bucket was 45; the card rendered current values, peaks and the saturation warning for 1h/6h/24h/7d (9/2/1/1 intervals) and a pre-v1.4.7.5 snapshot rendered without the new row. All CSS classes in the view already exist in `dist/pulse.css`. Parsing costs ~21 µs per sample (plus one `ps` and one `ss` call every 15 seconds, not measured on Linux). HTTP request latency unchanged (Servers recording runs only in `pulse:check`): 1000-query benchmark 173–184 ms vs 175–181 ms on v1.4.7.4. Not yet browser-tested; real `/proc`, `ps` and `ss` sampling only verifiable on Linux. (v1.4.7.5; then confirmed on the target DEV server: the row rendered with live data.)
* v1.4.7.6 card fixes (files overlaid on the v1.4.7.5 install; fixture checks 32/32 with swap/steal/storage history neither read, recorded nor shown, and the snapshot without them): headless Chrome (dark mode) on the local dashboard at 1900px and 1366px widths showed no horizontal overflow (card `scrollWidth` = `clientWidth`), the first row no longer stretched, the four metrics on one line and the saturation warning below; hovering every chart showed a complete two-line tooltip (e.g. `Oct 15, 15:31` / `avg 20% · peak 20%`) inside 144px- and 320px-wide charts, with no JavaScript errors.
* v1.4.7.7 1-second FPM sampling and split warning (files overlaid on the v1.4.7.5 install, disposable metrics database): a simulated `pulse:check` loop of 1800 beats (clock advanced one second per beat, ingest after every beat) with a 3-second queue burst (queue 4, 8/20 workers) and an 8-second saturation (20/20, queue 12) made 1800 `ps` and 1800 `ss` calls, re-read the pool configuration 121 times, wrote to the Pulse database on 120 beats, stored 1786 one-second FPM samples (the rest pending for the next window) but 120 CPU/load samples, peaks 20 workers / 12 queued and 11 queue-active seconds, aggregates only; the card showed `3 / 20 avg 3.1 · peak 20`, `0 avg 0.1 · peak 12 queued 11s` and one red plus one amber warning in 1h/6h/24h (red only in 7d, where both fall in one bucket); in-memory sampling costs ~9 µs per beat plus the two process calls (not measured on Linux). The earlier fixture checks (32/32) pass with the new wording. Headless Chrome (dark mode, 1900px and 1366px): no horizontal overflow, the four metrics on one line, both warnings readable, no JavaScript errors.
* Not yet run: Laravel 10+ runtime, Pulse's own test suite, PHPStan.

## [Unreleased](https://github.com/laravel/pulse/compare/v1.4.6...1.x)

## [v1.4.6](https://github.com/laravel/pulse/compare/v1.4.5...v1.4.6) - 2025-12-08

* Remove deprecated backticks by [@TomatoPana](https://github.com/TomatoPana) in https://github.com/laravel/pulse/pull/471

## [v1.4.5](https://github.com/laravel/pulse/compare/v1.4.4...v1.4.5) - 2025-11-28

* Add reload command by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/pulse/pull/469

## [v1.4.4](https://github.com/laravel/pulse/compare/v1.4.3...v1.4.4) - 2025-11-24

* [1.x] PHP 8.5 Compatibility by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/pulse/pull/467

## [v1.4.3](https://github.com/laravel/pulse/compare/v1.4.2...v1.4.3) - 2025-07-18

* Remove `--with` flag from test runner by [@joetannenbaum](https://github.com/joetannenbaum) in https://github.com/laravel/pulse/pull/459
* Prevent usage of unpatched livewire version by [@BertvanHoekelen](https://github.com/BertvanHoekelen) in https://github.com/laravel/pulse/pull/464

## [v1.4.2](https://github.com/laravel/pulse/compare/v1.4.1...v1.4.2) - 2025-05-19

* Update logo by [@iamdavidhill](https://github.com/iamdavidhill) in https://github.com/laravel/pulse/pull/453
* [1.x] Fix Dropdown Hover Effect by [@devajmeireles](https://github.com/devajmeireles) in https://github.com/laravel/pulse/pull/456
* [1.x] Adding Missing `space-y-1` by [@devajmeireles](https://github.com/devajmeireles) in https://github.com/laravel/pulse/pull/458

## [v1.4.1](https://github.com/laravel/pulse/compare/v1.4.0...v1.4.1) - 2025-03-30

* Test Improvements by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/pulse/pull/449
* [1.x] Make `PulseMigration` compatible with Laravel v12.4: `public shouldRun()` by [@onlime](https://github.com/onlime) in https://github.com/laravel/pulse/pull/451

## [v1.4.0](https://github.com/laravel/pulse/compare/v1.3.4...v1.4.0) - 2025-02-11

* [1.x] Fix background color for overscroll-bounce by [@angelej](https://github.com/angelej) in https://github.com/laravel/pulse/pull/442
* [1.x] Fix: Keep select fields open by [@angelej](https://github.com/angelej) in https://github.com/laravel/pulse/pull/441
* Allow system default of dark mode if theme value is unrecognized by [@J-T-McC](https://github.com/J-T-McC) in https://github.com/laravel/pulse/pull/440
* [1.x] Fix empty state for the exceptions card by [@angelej](https://github.com/angelej) in https://github.com/laravel/pulse/pull/439
* [1.x] Encode slow queries if highlighting is disabled by [@angelej](https://github.com/angelej) in https://github.com/laravel/pulse/pull/443

## [v1.3.4](https://github.com/laravel/pulse/compare/v1.3.3...v1.3.4) - 2025-01-28

* Supports Laravel 12 by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/pulse/pull/438

## [v1.3.3](https://github.com/laravel/pulse/compare/v1.3.2...v1.3.3) - 2025-01-02

* [1.x] Add nested controller route grouping by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/433
* [1.x] Supports Relay driver on PHP 8.4 by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/pulse/pull/421

## [v1.3.2](https://github.com/laravel/pulse/compare/v1.3.1...v1.3.2) - 2024-12-12

* [1.x] Prevent memory leak on `pulse:work` command when using Telescope by [@gdebrauwer](https://github.com/gdebrauwer) in https://github.com/laravel/pulse/pull/430

## [v1.3.1](https://github.com/laravel/pulse/compare/v1.3.0...v1.3.1) - 2024-12-11

* Method visibility patch by [@angelej](https://github.com/angelej) in https://github.com/laravel/pulse/pull/429

## [v1.3.0](https://github.com/laravel/pulse/compare/v1.2.7...v1.3.0) - 2024-12-02

* [1.x] Ensure run at is always returned to the front end in UTC by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/425
* [1.x] Add Configurable Trim Duration for Pulse Data Storage by [@tharlei](https://github.com/tharlei) in https://github.com/laravel/pulse/pull/424
* [1.x] Prevent memory leak on `pulse:check` command when using Telescope by [@gdebrauwer](https://github.com/gdebrauwer) in https://github.com/laravel/pulse/pull/426
* [1.x] Use dedicated config value for trimming storage by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/427

## [v1.2.7](https://github.com/laravel/pulse/compare/v1.2.6...v1.2.7) - 2024-11-25

* [1.x] Supports PHP 8.4 by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/pulse/pull/419
* [1.x] Format dates on graph updates by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/423

## [v1.2.6](https://github.com/laravel/pulse/compare/v1.2.5...v1.2.6) - 2024-11-12

* [1.x] Format dates to browsers timezone by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/417
* [1.x] Update NPM dependencies by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/418

## [v1.2.5](https://github.com/laravel/pulse/compare/v1.2.4...v1.2.5) - 2024-09-03

* [1.x] Removed useless default null values for env method by [@siarheipashkevich](https://github.com/siarheipashkevich) in https://github.com/laravel/pulse/pull/404

## [v1.2.4](https://github.com/laravel/pulse/compare/v1.2.3...v1.2.4) - 2024-07-08

* Skip unreadable filesystems by [@27pchrisl](https://github.com/27pchrisl) in https://github.com/laravel/pulse/pull/392

## [v1.2.3](https://github.com/laravel/pulse/compare/v1.2.2...v1.2.3) - 2024-06-04

* [1.x] Fix DB prefixing for Postgres and SQLite by [@jessarcher](https://github.com/jessarcher) in https://github.com/laravel/pulse/pull/386

## [v1.2.2](https://github.com/laravel/pulse/compare/v1.2.1...v1.2.2) - 2024-05-26

* [1.x] Use Contracts for common services by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/376
* [1.x] Fix static analysis by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/379

## [v1.2.1](https://github.com/laravel/pulse/compare/v1.2.0...v1.2.1) - 2024-05-22

* Fix exception from missing import on select component by [@rginnow](https://github.com/rginnow) in https://github.com/laravel/pulse/pull/373

## [v1.2.0](https://github.com/laravel/pulse/compare/v1.1.0...v1.2.0) - 2024-05-17

* [1.x] Ensure missing user does not throw a type error by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/366
* [1.x] Allow thresholds of slow queries, jobs and requests to be customised by regex pattern by [@matheus-carvalho](https://github.com/matheus-carvalho) in https://github.com/laravel/pulse/pull/340
* [1.x] Removes the padding right when there is no scroll active. by [@xiCO2k](https://github.com/xiCO2k) in https://github.com/laravel/pulse/pull/369
* [1.x] Associate label with select by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/371
* [1.x] Improve config for 3rd party cards by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/372

## [v1.1.0](https://github.com/laravel/pulse/compare/v1.0.0...v1.1.0) - 2024-05-06

* [1.x] Ignore offline servers (continued) by [@JustinElst](https://github.com/JustinElst) in https://github.com/laravel/pulse/pull/355
* Add support for Relay by [@riasvdv](https://github.com/riasvdv) in https://github.com/laravel/pulse/pull/358
* Added reverb to default vendor cache keys by [@phlawlessDevelopment](https://github.com/phlawlessDevelopment) in https://github.com/laravel/pulse/pull/360
* [1.x] Rename highlighting prop by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/362
* [1.x] Cache testing improvements by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/pulse/pull/363

## v1.0.0 - 2024-04-30

Initial release of Pulse. For more information, please consult the [Pulse documentation](https://laravel.com/docs/pulse).
