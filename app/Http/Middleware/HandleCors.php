<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\HandleCors as BaseHandleCors;
use Symfony\Component\HttpFoundation\Response;

class HandleCors extends BaseHandleCors
{
    /**
     * Handle an incoming request.
     * Pastikan request dengan method OPTIONS langsung merespons dengan HTTP 204
     * tanpa menyentuh route controller maupun koneksi database.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            $response = parent::handle($request, function ($req) {
                return new Response('', 204);
            });

            if ($response->getStatusCode() !== 204) {
                $response->setStatusCode(204);
            }

            return $response;
        }

        return parent::handle($request, $next);
    }
}
