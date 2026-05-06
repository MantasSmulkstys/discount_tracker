<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BasicAuthProtection
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        $username = env('BASIC_AUTH_USERNAME');
        $password = env('BASIC_AUTH_PASSWORD');

        if (! $username || ! $password) {
            abort(403);
        }

        if ($request->getUser() !== $username || $request->getPassword() !== $password) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Protected Demo"',
            ]);
        }

        return $next($request);
    }
}
