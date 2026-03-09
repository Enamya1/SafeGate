<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SanctumTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (! is_string($token) || $token === '') {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user = $accessToken->tokenable;

        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');

        if (is_string($authorization) && $authorization !== '' && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        $token =
            $request->headers->get('X-Access-Token')
            ?? $request->headers->get('X-Authorization')
            ?? $request->query('access_token')
            ?? $request->input('access_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        if (str_starts_with($token, 'Bearer ')) {
            return substr($token, 7);
        }

        return $token;
    }
}
