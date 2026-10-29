<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordRecoveryController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        try {
            DB::transaction(function () use ($input) {
                // Serialize token issuance with resets for this account.
                User::where('email', $input['email'])->lockForUpdate()->first();
                Password::broker('users')->sendResetLink([...$input, 'is_active' => true]);
            });
        } catch (\Throwable $error) {
            // Transport errors must not reveal which accounts exist, or log reset tokens.
            Log::error('Password recovery delivery failed. Check mail and database configuration.', ['exception_type' => $error::class]);
        }

        return response()->json(['message' => 'If an active account matches that email, a password reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:256'],
            'password' => ['bail', 'required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) $fail('The password must not exceed 72 UTF-8 bytes.');
            }],
        ]);

        $status = DB::transaction(function () use ($input) {
            // The broker verifies and consumes the token inside the same locked transaction.
            User::where('email', $input['email'])->lockForUpdate()->first();
            return Password::broker('users')->reset([...$input, 'is_active' => true], function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                AuditEvent::create(['user_id' => $user->id, 'action' => 'account.password_reset', 'subject' => 'user', 'subject_id' => $user->id]);
                DB::afterCommit(fn () => event(new PasswordReset($user)));
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Request a new link.']);
        }

        // Never sign in through recovery or refresh an existing session's password marker.
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Your password has been reset. Sign in with your new password.', 'csrf_token' => csrf_token()]);
    }
}
