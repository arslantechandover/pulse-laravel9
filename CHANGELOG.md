# Release Notes

## Unreleased (laravel9-support fork, based on v1.4.7)

Laravel 9.52 compatibility. On Laravel 10+ each change defers to the original upstream code path.

### Added

* `Laravel\Pulse\Support\Laravel9HttpFactory`: Laravel 9-only `Http\Client\Factory` subclass providing Laravel 10's `globalMiddleware()`. Bound by `SlowOutgoingRequests` only when the method is missing and no other `Factory` binding exists.
* `Livewire\SlowUserRequests` card (`pulse.slow-user-requests`, view `livewire/slow-user-requests`), added to the default dashboard after Slow Requests. Shows the top 10 users by `slow_user_request` count (already recorded by the `SlowRequests` recorder; no recording or storage change). Counts are scaled by the `SlowRequests` recorder's sample rate and the header shows its threshold. The existing Application Usage card with `type="slow_requests"` reads these from its `usage` dropdown/URL value instead of `type`, so it scales by the user-requests sample rate when the rates differ; this card avoids that. Fork-only feature, not Laravel 9-specific.

### Changed

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
