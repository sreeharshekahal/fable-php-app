<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\User;
use App\Services\DjangoPasswordService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthSessionController extends Controller
{
    /**
     * Login user with username/email and password.
     * Matches Django rest_auth LoginView + custom TokenSerializer in fable.access.serializers.
     */
    public function login(Request $request, DjangoPasswordService $passwordService)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'nullable|string',
            'email' => 'nullable|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $username = $request->input('username');
        $email = $request->input('email');
        $password = $request->input('password');

        if (!$username && !$email) {
            return response()->json([
                'non_field_errors' => ['Must include either "username" or "email" and "password".'],
            ], 400);
        }

        // Find user by username or email
        $userQuery = DB::table('auth_user');
        if ($username) {
            $userQuery->where('username', $username);
        } elseif ($email) {
            $userQuery->whereRaw('LOWER(email) = ?', [strtolower(trim($email))]);
        }

        $user = $userQuery->first();

        if (!$user || !$passwordService->checkPassword($password, $user->password)) {
            return response()->json([
                'non_field_errors' => ['Unable to log in with provided credentials.'],
            ], 400);
        }

        if (isset($user->is_active) && !$user->is_active) {
            return response()->json([
                'non_field_errors' => ['User account is disabled.'],
            ], 400);
        }

        // Retrieve or generate token (Django Token model: 40-character hex)
        $tokenRecord = DB::table('authtoken_token')->where('user_id', $user->id)->first();
        if (!$tokenRecord) {
            $tokenKey = bin2hex(random_bytes(20)); // 40 hex chars
            $now = Carbon::now('UTC');
            DB::table('authtoken_token')->insert([
                'key' => $tokenKey,
                'user_id' => $user->id,
                'created' => $now,
            ]);
            $created = $now->toIso8601String();
        } else {
            $tokenKey = $tokenRecord->key;
            $created = Carbon::parse($tokenRecord->created)->toIso8601String();
        }

        // Update last_login
        DB::table('auth_user')->where('id', $user->id)->update([
            'last_login' => Carbon::now('UTC'),
        ]);

        $isAdmin = DB::table('access_admin')->where('user_id', $user->id)->exists();
        $isTeacher = DB::table('access_teacher')->where('user_id', $user->id)->exists();

        return response()->json([
            'key' => $tokenKey,
            'user' => $user->id,
            'created' => $created,
            'is_admin' => $isAdmin,
            'is_teacher' => $isTeacher,
        ], 200);
    }

    /**
     * Logout user by deleting their token.
     * Matches Django rest_auth LogoutView.
     */
    public function logout(Request $request)
    {
        $user = auth()->user();
        if ($user) {
            DB::table('authtoken_token')->where('user_id', $user->id)->delete();
        }

        return response()->json([
            'detail' => 'Successfully logged out.',
        ], 200);
    }

    /**
     * Return user details for currently authenticated user.
     * Matches Django rest_auth UserDetailsView.
     */
    public function user(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized - User not authenticated'], 401);
        }

        return response()->json([
            'pk' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ], 200);
    }
}

