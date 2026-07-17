<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class AdminPermission
{
    /**
     * Handle an incoming request.
     * Checks if the authenticated user is an admin (exists in access_admin table)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized - User not authenticated'], 401);
        }

        // Check if user exists in access_admin table
        $isAdmin = DB::table('access_admin')
            ->where('user_id', $user->id)
            ->exists();

        if (!$isAdmin) {
            return response()->json([
                'error' => 'Forbidden - Admin permissions required'
            ], 403);
        }

        return $next($request);
    }
}
