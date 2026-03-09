<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenFromQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $existingAuthorization = $request->headers->get('Authorization');

        if (is_string($existingAuthorization) && $existingAuthorization !== '') {
            return $next($request);
        }

        $token =
            $request->headers->get('X-Access-Token')
            ?? $request->headers->get('X-Authorization')
            ?? $request->query('access_token')
            ?? $request->input('access_token');

        if (is_string($token) && $token !== '') {
            $authorization = str_starts_with($token, 'Bearer ') ? $token : 'Bearer '.$token;
            $request->headers->set('Authorization', $authorization);
        }

        return $next($request);
    }
}
