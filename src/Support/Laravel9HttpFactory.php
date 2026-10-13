<?php

namespace Laravel\Pulse\Support;

use Illuminate\Http\Client\Factory;

/**
 * Laravel 9 compatibility: provides Laravel 10's Factory::globalMiddleware().
 *
 * @internal
 */
class Laravel9HttpFactory extends Factory
{
    /**
     * The middleware to apply to every request.
     *
     * @var array<int, callable>
     */
    protected $globalMiddleware = [];

    /**
     * Add middleware to apply to every request.
     *
     * @param  callable  $middleware
     * @return $this
     */
    public function globalMiddleware($middleware)
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Create a new pending request instance for this factory.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function newPendingRequest()
    {
        return tap(parent::newPendingRequest(), function ($request) {
            foreach ($this->globalMiddleware as $middleware) {
                $request->withMiddleware($middleware);
            }
        });
    }
}
