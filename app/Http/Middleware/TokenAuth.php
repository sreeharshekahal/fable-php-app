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
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
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

        // Define your static token
        $staticToken = env('API_TOKEN');

        // First, check if the token matches the static token
        if ($staticToken && $token === $staticToken) {
            return $next($request);
        }

        // Otherwise, check if it's a dynamic Django token
        $authToken = AuthToken::with('user')->where('key', $token)->first();

        if ($authToken) {
            $user = $authToken->user;
            auth()->setUser($user);
            $request->merge(['authenticated_user' => $user]);
            return $next($request);
        }

        return response()->json(['error' => 'Unauthorized - Invalid token'], 401);
    }
}

