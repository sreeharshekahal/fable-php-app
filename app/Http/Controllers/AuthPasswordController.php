<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DjangoPasswordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthPasswordController extends Controller
{
    /**
     * Request password reset email.
     * Matches Django rest_auth PasswordResetView + custom PasswordResetSerializer.
     */
    public function passwordReset(Request $request, DjangoPasswordService $passwordService)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $email = $request->input('email');

        // Check if user with this email exists
        $userExists = DB::table('auth_user')
            ->where('email', $email)
            ->exists();

        if (!$userExists) {
            return response()->json(['email' => ['Invalid e-mail address']], 400);
        }

        // Must have an associated teacher or admin profile
        $users = DB::table('auth_user as u')
            ->where('u.email', $email)
            ->where(function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('access_teacher')
                        ->whereColumn('access_teacher.user_id', 'u.id');
                })->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('access_admin')
                        ->whereColumn('access_admin.user_id', 'u.id');
                });
            })
            ->get();

        if ($users->isEmpty()) {
            return response()->json(['detail' => 'User is neither Teacher nor Admin'], 400);
        }

        $webAppBaseUrl = rtrim(config('app.web_app_base_url', env('WEB_APP_BASE_URL', config('app.url'))), '/');
        $fromEmail = config('mail.from.address', env('DEFAULT_FROM_EMAIL', 'info@fable.com'));
        $fromName = config('mail.from.name', 'FABLe');

        foreach ($users as $user) {
            $organisationTitle = 'Administration';
            $teacher = DB::table('access_teacher')
                ->where('user_id', $user->id)
                ->first();

            if ($teacher && $teacher->organisation_id) {
                $org = DB::table('organisation_organisation')
                    ->where('id', $teacher->organisation_id)
                    ->first();
                if ($org) {
                    $organisationTitle = $org->title;
                }
            }

            // Decrypt first name if encrypted in storage
            $firstName = $user->first_name;
            if ($firstName && str_contains($firstName, 'eyJpdiI6')) {
                try {
                    $firstName = Crypt::decryptString($firstName);
                } catch (\Exception $e) {}
            }
            $user->first_name = $firstName;

            $token = $passwordService->makeResetToken($user);
            $uidb64 = $passwordService->urlsafeBase64Encode((string) $user->id);

            // Match Django: forgot-password/<uidb64>/<token>/
            $resetUrl = "{$webAppBaseUrl}/forgot-password/{$uidb64}/{$token}/";

            try {
                Mail::send('reset_password', [
                    'user' => $user,
                    'reset_url' => $resetUrl,
                    'uid' => $uidb64,
                    'token' => $token,
                    'organisation' => $organisationTitle,
                ], function ($message) use ($user, $fromEmail, $fromName) {
                    $message->to($user->email)
                        ->from($fromEmail, $fromName)
                        ->subject('Set Password of your FABLe account');
                });
            } catch (\Exception $e) {
                // If mail sending fails, continue or log error
            }
        }

        return response()->json([
            'detail' => 'Password reset e-mail has been sent.'
        ], 200);
    }

    /**
     * Confirm password reset with token + uid.
     * Matches Django rest_auth PasswordResetConfirmView.
     */
    public function passwordResetConfirm(Request $request, DjangoPasswordService $passwordService, ?string $routeUid = null, ?string $routeToken = null)
    {
        $payload = $request->all();
        if ($routeUid && !isset($payload['uid'])) {
            $payload['uid'] = $routeUid;
        }
        if ($routeToken && !isset($payload['token'])) {
            $payload['token'] = $routeToken;
        }

        $validator = Validator::make($payload, [
            'uid' => 'required|string',
            'token' => 'required|string',
            'new_password1' => 'required|string|max:128',
            'new_password2' => 'required|string|max:128|same:new_password1',
        ], [
            'new_password2.same' => "The two password fields didn't match.",
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $decodedUserId = $passwordService->urlsafeBase64Decode($payload['uid']);
        if (!$decodedUserId) {
            return response()->json(['uid' => ['Invalid value']], 400);
        }

        $user = DB::table('auth_user')->where('id', $decodedUserId)->first();
        if (!$user) {
            return response()->json(['uid' => ['Invalid value']], 400);
        }

        if (!$passwordService->checkResetToken($user, $payload['token'])) {
            return response()->json(['token' => ['Invalid value']], 400);
        }

        // Hash new password using Django-compatible PBKDF2
        $newHashedPassword = $passwordService->makePassword($payload['new_password1']);

        DB::table('auth_user')
            ->where('id', $user->id)
            ->update([
                'password' => $newHashedPassword,
            ]);

        return response()->json([
            'detail' => 'Password has been reset with the new password.'
        ], 200);
    }

    /**
     * Change password for authenticated user.
     * Matches Django rest_auth PasswordChangeView.
     */
    public function passwordChange(Request $request, DjangoPasswordService $passwordService)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized - User not authenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string|max:128',
            'new_password1' => 'required|string|max:128',
            'new_password2' => 'required|string|max:128|same:new_password1',
        ], [
            'new_password2.same' => "The two password fields didn't match.",
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Fetch fresh user record with password
        $dbUser = DB::table('auth_user')->where('id', $user->id)->first();
        if (!$dbUser || !$passwordService->checkPassword($request->input('old_password'), $dbUser->password)) {
            return response()->json(['old_password' => ['Invalid password']], 400);
        }

        $newHashedPassword = $passwordService->makePassword($request->input('new_password1'));

        DB::table('auth_user')
            ->where('id', $user->id)
            ->update([
                'password' => $newHashedPassword,
            ]);

        return response()->json([
            'detail' => 'New password has been saved.'
        ], 200);
    }
}

