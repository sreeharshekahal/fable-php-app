<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use App\Models\AuthToken;

class TeacherAuth
{
    /**
     * Handle an incoming request.
     * Combined middleware that checks both token authentication AND teacher permissions
     * This is a standalone middleware that doesn't depend on TokenAuth
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ============================================
        // 1. AUTHENTICATE TOKEN (like TokenAuth)
        // ============================================

        // Extract token from Authorization header
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Token ')) {
            return response()->json(['error' => 'Unauthorized - No token provided'], 401);
        }

        // Get token value (remove "Token " prefix)
        $token = substr($authHeader, 6);

        // Find token in database with user
        $authToken = AuthToken::with('user')->where('key', $token)->first();

        if (!$authToken) {
            return response()->json(['error' => 'Unauthorized - Invalid token'], 401);
        }

        // Attach user to request for use in controllers
        $request->merge(['authenticated_user' => $authToken->user]);

        // Also set in Laravel's auth system for convenience
        auth()->setUser($authToken->user);

        // ============================================
        // 2. CHECK TEACHER PERMISSION
        // ============================================

        $user = $authToken->user;

        // Check if user exists in access_teacher table
        $isTeacher = DB::table('access_teacher')
            ->where('user_id', $user->id)
            ->exists();

        if (!$isTeacher) {
            return response()->json([
                'error' => 'Forbidden - Teacher permissions required'
            ], 403);
        }

        // User is authenticated AND is a teacher, allow request to proceed
        return $next($request);
    }
}
