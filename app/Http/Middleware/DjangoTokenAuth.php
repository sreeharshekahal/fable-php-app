<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AuthToken;
use Illuminate\Support\Facades\DB;

class DjangoTokenAuth
{
    /**
     * Handle an incoming request.
     * Authenticates Django-style tokens and sets the user.
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

        $authToken = AuthToken::with('user')->where('key', $token)->first();

        if (!$authToken) {
            return response()->json(['error' => 'Unauthorized - Invalid token'], 401);
        }

        $user = $authToken->user;
        auth()->setUser($user);
        $request->merge(['authenticated_user' => $user]);

        return $next($request);
    }
}
