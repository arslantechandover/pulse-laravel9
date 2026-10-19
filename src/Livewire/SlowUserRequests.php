<?php

namespace Laravel\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Recorders\ClientRequests;
use Laravel\Pulse\Recorders\Concerns\Thresholds;
use Laravel\Pulse\Recorders\SlowRequests;
use Livewire\Attributes\Lazy;

/**
 * @internal
 */
#[Lazy]
class SlowUserRequests extends Card
{
    use Concerns\LatestSlowRequests, Thresholds;

    /**
     * The number of endpoints shown per user.
     */
    protected const ENDPOINTS_PER_USER = 5;

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$slowUserRequests, $time, $runAt] = $this->remember(function () {
            // Fork: with client timing enabled, also count requests that were slow only for the client and show the
            // slowest client total (see ClientRequests::recordUser())...
            $client = (bool) Config::get('pulse.recorders.'.ClientRequests::class.'.enabled');

            $counts = $client
                ? $this->aggregateTypes(['slow_user_request', 'slow_client_user_request'], 'count', limit: 1000)
                    ->map(fn ($row) => (object) ['key' => $row->key, 'count' => (int) ($row->slow_user_request ?? 0) + (int) ($row->slow_client_user_request ?? 0)])
                    ->filter(fn ($row) => $row->key !== null && $row->count > 0)
                    ->sortByDesc('count')
                    ->take(10)
                    ->values()
                : $this->aggregate('slow_user_request', 'count', limit: 10);

            $userKeys = $counts->pluck('key')->map(fn ($key) => (string) $key);

            $server = $this->aggregate('slow_user_endpoint', ['max', 'count'], 'max', limit: 1000)->keyBy('key');
            $clientSlowest = $client ? $this->aggregateTypes(['slow_client_user_endpoint'], 'max', limit: 1000)->keyBy('key') : collect();
            $clientOnly = $client ? $this->aggregateTypes(['slow_client_user_endpoint_only'], 'count', limit: 1000)->keyBy('key') : collect();

            // Fork: the latest slow request per user endpoint and per user (from the stored per-request entries)...
            $latest = $this->latestSlowRequests('slow_user_endpoint', 'slow_client_user_endpoint');
            $latestByUser = $this->latestByUser($latest);

            // Fork: the breakdown of the slowest and the latest request per user endpoint and per user...
            $details = $this->clientRequestDetails()->filter(fn ($detail) => $detail['user'] !== null);
            [$slowestDetails, $latestDetails] = $this->slowestAndLatestDetails($details, fn ($detail) => json_encode([$detail['user'], $detail['method'], $detail['path']]));
            [$slowestUserDetails, $latestUserDetails] = $this->slowestAndLatestDetails($details, fn ($detail) => $detail['user']);
            $slowestText = fn (?array $detail, $slowest) => $detail !== null && $slowest !== null && (int) $slowest === $detail['total']
                ? static::clientBreakdownText($detail['parts'], $detail['total'])
                : null;

            $endpoints = $server->keys()->merge($clientSlowest->keys())->filter(fn ($key) => $key !== '')->unique()
                ->map(function ($key) use ($server, $clientSlowest, $clientOnly, $latest, $slowestDetails, $latestDetails, $slowestText) {
                    [$userKey, $method, $uri] = json_decode($key, flags: JSON_THROW_ON_ERROR);
                    $clientSlowestValue = $clientSlowest->get($key)?->slow_client_user_endpoint;

                    return (object) [
                        'user_key' => (string) $userKey,
                        'method' => $method,
                        'uri' => $uri,
                        'count' => (int) ($server->get($key)?->count ?? 0) + (int) ($clientOnly->get($key)?->slow_client_user_endpoint_only ?? 0),
                        'slowest' => $server->get($key)?->max,
                        'client_slowest' => $clientSlowestValue,
                        'slowest_breakdown' => $slowestText($slowestDetails->get($key), $clientSlowestValue),
                        'threshold' => $this->threshold($uri, SlowRequests::class),
                        'latest' => $this->withLatestBreakdown($latest->get($key), $latestDetails->get($key)),
                    ];
                })
                ->whereIn('user_key', $userKeys->all())
                ->sortByDesc(fn ($endpoint) => max($endpoint->slowest ?? 0, $endpoint->client_slowest ?? 0))
                ->groupBy('user_key');

            $users = Pulse::resolveUsers($counts->pluck('key'));

            return $counts->map(function ($row) use ($users, $endpoints, $latestByUser, $slowestUserDetails, $latestUserDetails, $slowestText) {
                $userEndpoints = $endpoints->get((string) $row->key, collect())->values();
                $clientSlowestValue = $userEndpoints->max('client_slowest');

                return (object) [
                    'key' => $row->key,
                    'user' => $users->find($row->key),
                    'count' => (int) $row->count,
                    'slowest' => $userEndpoints->max('slowest'),
                    'client_slowest' => $clientSlowestValue,
                    'slowest_breakdown' => $slowestText($slowestUserDetails->get((string) $row->key), $clientSlowestValue),
                    'latest' => $this->withLatestBreakdown($latestByUser->get((string) $row->key), $latestUserDetails->get((string) $row->key)),
                    'endpoints' => $userEndpoints,
                ];
            });
        });

        return View::make('pulse::livewire.slow-user-requests', [
            'time' => $time,
            'runAt' => $runAt,
            'slowUserRequests' => $slowUserRequests,
            'endpointsPerUser' => static::ENDPOINTS_PER_USER,
            'config' => [
                'threshold' => Config::get('pulse.recorders.'.SlowRequests::class.'.threshold'),
                'sample_rate' => Config::get('pulse.recorders.'.SlowRequests::class.'.sample_rate'),
            ],
        ]);
    }
}
