<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AuthToken;

class TokenAuth
{
    /**
     * Handle an incoming request.
     *
     * Supports both Static API token and Django Auth Tokens
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json(['error' => 'Unauthorized - No token provided'], 401);
        }

        $token = null;
        if (str_starts_with($authHeader, 'Token ')) {
            $token = substr($authHeader, 6);
        } elseif (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if (!$token) {
            return response()->json(['error' => 'Unauthorized - Invalid authorization format'], 401);
        }

        // 1. Check if the token matches the static API token
        $staticToken = env('API_TOKEN');
        if (!empty($staticToken) && is_string($staticToken) && hash_equals($staticToken, $token)) {
            return $next($request);
        }

        // 2. Check if it's a dynamic Django auth token
        $authToken = AuthToken::with('user')->where('key', $token)->first();

        if ($authToken && $authToken->user) {
            $user = $authToken->user;
            auth()->setUser($user);
            $request->setUserResolver(function () use ($user) {
                return $user;
            });
            $request->attributes->set('authenticated_user', $user);

            return $next($request);
        }

        return response()->json(['error' => 'Unauthorized - Invalid token'], 401);
    }
}
