<?php

namespace Laravel\Pulse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\ClientRequests;
use Laravel\Pulse\Recorders\Concerns\LivewireRoutes;
use Laravel\Pulse\Recorders\SlowRequests;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fork: tag responses with their route key and server-side timing for the web server's client timing line.
 *
 * The web server logs the header and removes it, so clients never receive it.
 *
 * @internal
 */
class TagClientRequests
{
    use LivewireRoutes;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response && $request->route() instanceof Route) {
            $this->pulse->rescue(function () use ($request, $response) {
                [$path, $via] = $this->resolveRoutePath($request);

                if (collect(Config::get('pulse.recorders.'.ClientRequests::class.'.ignore', []))->contains(fn (string $pattern) => preg_match($pattern, $path))) {
                    return;
                }

                $recorder = $this->pulse->recorders()->first(fn ($recorder) => $recorder instanceof SlowRequests);
                $timing = $recorder instanceof SlowRequests ? $recorder->clientTiming($request) : [];

                // Streamed / file responses generate their content after this point, so their server time is taken
                // from the web server's upstream time instead (DB and HTTP so far are kept as lower bounds)...
                if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
                    unset($timing['q'], $timing['p']);
                }

                // The same user ID the Slow User Requests / Application Usage cards use (none for guests)...
                $user = $this->pulse->resolveAuthenticatedUserId();

                $value = rawurlencode(json_encode(['k' => [$request->method(), $path, $via], ...$timing, ...($user === null ? [] : ['u' => $user])], flags: JSON_THROW_ON_ERROR));

                if (strlen($value) <= 1500) {
                    $response->headers->set(ClientRequests::HEADER, $value);
                }
            });
        }

        return $response;
    }
}
