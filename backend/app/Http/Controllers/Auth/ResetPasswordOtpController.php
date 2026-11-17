<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordOtp as ResetPasswordOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ResetPasswordOtpController extends Controller
{
    private const GENERIC_REQUEST_MESSAGE = 'If the account exists, a verification code has been sent.';
    private const INVALID_OTP_MESSAGE = 'Invalid or expired verification code.';
    private const INVALID_SESSION_MESSAGE = 'Invalid or expired password reset session.';
    private const TOO_MANY_ATTEMPTS_MESSAGE = 'Too many attempts. Request a new code.';

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $mailPayload = $this->issueOtpIfEligible($email);

        $this->dispatchMailAfterResponse($mailPayload);

        return $this->genericRequestResponse();
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $this->normalizeEmail($validated['email']);

        if ($this->resendRateLimited($request, $email)) {
            return $this->genericRequestResponse();
        }

        $mailPayload = $this->issueOtpIfEligible($email, enforceCooldown: true);

        $this->dispatchMailAfterResponse($mailPayload);

        return $this->genericRequestResponse();
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $email = $this->normalizeEmail($validated['email']);

        return DB::transaction(function () use ($email, $validated): JsonResponse {
            $record = PasswordResetOtp::query()->where('email', $email)->lockForUpdate()->first();

            if (! $record || ! $record->otp_hash || ! $record->expires_at || $record->expires_at->isPast() || $record->verified_at) {
                return response()->json(['message' => self::INVALID_OTP_MESSAGE], 422);
            }

            if ($record->attempts >= 5) {
                return response()->json(['message' => self::TOO_MANY_ATTEMPTS_MESSAGE], 429);
            }

            if (! Hash::check($validated['otp'], $record->otp_hash)) {
                $record->increment('attempts');

                return response()->json(['message' => self::INVALID_OTP_MESSAGE], 422);
            }

            $token = Str::random(64);

            $record->forceFill([
                'otp_hash' => null,
                'attempts' => 0,
                'verified_at' => now(),
                'reset_token_hash' => Hash::make($token),
                'reset_token_expires_at' => now()->addMinutes(10),
            ])->save();

            return response()->json([
                'message' => 'OTP verified successfully.',
                'reset_token' => $token,
            ]);
        });
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = $this->normalizeEmail($validated['email']);

        return DB::transaction(function () use ($email, $validated): JsonResponse {
            $record = PasswordResetOtp::query()->where('email', $email)->lockForUpdate()->first();

            if (! $this->validResetToken($record, $validated['token'])) {
                return response()->json(['message' => self::INVALID_SESSION_MESSAGE], 422);
            }

            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                return response()->json(['message' => self::INVALID_SESSION_MESSAGE], 422);
            }

            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
            ])->save();

            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            $this->deleteUserSessions($user->getKey());

            $record->delete();

            return response()->json(['message' => 'Password reset successfully.']);
        });
    }

    /**
     * @return array{email:string,otp:string}|null
     */
    private function issueOtpIfEligible(string $email, bool $enforceCooldown = false): ?array
    {
        try {
            return DB::transaction(function () use ($email, $enforceCooldown): ?array {
                $record = PasswordResetOtp::query()->where('email', $email)->lockForUpdate()->first();
                $user = User::query()->where('email', $email)->lockForUpdate()->first();

                if ($enforceCooldown && $record && $record->last_sent_at) {
                    $lastSentAt = $record->last_sent_at;
                    $cooldownUntil = $lastSentAt->copy()->addSeconds(60);
                    if ($cooldownUntil->gt(now())) {
                        return null;
                    }
                }

                if (! $user || $user->status !== 'active') {
                    return null;
                }

                $otp = (string) random_int(100000, 999999);

                PasswordResetOtp::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'otp_hash' => Hash::make($otp),
                        'expires_at' => now()->addMinutes(10),
                        'attempts' => 0,
                        'reset_token_hash' => null,
                        'reset_token_expires_at' => null,
                        'verified_at' => null,
                        'last_sent_at' => now(),
                    ]
                );

                return ['email' => $email, 'otp' => $otp];
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function resendRateLimited(Request $request, string $email): bool
    {
        $key = 'resend-reset-otp:'.sha1($email.'|'.(string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    /**
     * @param  array{email:string,otp:string}|null  $mailPayload
     */
    private function dispatchMailAfterResponse(?array $mailPayload): void
    {
        if (! $mailPayload) {
            return;
        }

        dispatch(function () use ($mailPayload): void {
            Mail::to($mailPayload['email'])->send(new ResetPasswordOtpMail($mailPayload['otp']));
        })->afterResponse();
    }

    private function deleteUserSessions(int|string|null $userId): void
    {
        if ($userId === null) {
            return;
        }

        $table = (string) config('session.table', 'sessions');

        try {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function genericRequestResponse(): JsonResponse
    {
        return response()->json(['message' => self::GENERIC_REQUEST_MESSAGE]);
    }

    private function validResetToken(?PasswordResetOtp $record, string $token): bool
    {
        return $record
            && $record->reset_token_hash
            && $record->reset_token_expires_at
            && $record->reset_token_expires_at->isFuture()
            && Hash::check($token, $record->reset_token_hash);
    }
}
