<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class VerifyUserRole
{
    private const ROLE_TABLES = [
        'admin' => 'access_admin',
        'teacher' => 'access_teacher',
    ];

    /**
     * Requires authentication middleware to run first.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Reject missing or unsupported roles.
        if (
            empty($roles) ||
            array_diff($roles, array_keys(self::ROLE_TABLES))
        ) {
            throw new InvalidArgumentException(
                'Invalid role middleware configuration.'
            );
        }

        foreach ($roles as $role) {
            $hasRole = DB::table(self::ROLE_TABLES[$role])
                ->where('user_id', $user->getAuthIdentifier())
                ->exists();

            if ($hasRole) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => 'Restricted for ' . implode(', ', $roles) . ' users.',
        ], Response::HTTP_FORBIDDEN);
    }
}
