<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class TeacherPermission
{
    /**
     * Handle an incoming request.
     * Checks if the authenticated user is a teacher (exists in access_teacher table)
     * This middleware should be used after TokenAuth middleware
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get authenticated user from TokenAuth middleware
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized - User not authenticated'], 401);
        }

        // Check if user exists in access_teacher table
        $isTeacher = DB::table('access_teacher')
            ->where('user_id', $user->id)
            ->exists();

        if (!$isTeacher) {
            return response()->json([
                'error' => 'Forbidden - Teacher permissions required'
            ], 403);
        }

        // User is a teacher, allow request to proceed
        return $next($request);
    }
}
