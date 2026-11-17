<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordOtp as ResetPasswordOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'mail.default' => 'array',
        ]);

        if (method_exists(RateLimiter::class, 'clear')) {
            // no global clear; keys are per request/email in tests as needed
        }
    }

    private function captureOtp(string $email): string
    {
        $mail = null;
        Mail::assertSent(ResetPasswordOtpMail::class, function (ResetPasswordOtpMail $sent) use ($email, &$mail): bool {
            if ($sent->hasTo($email)) {
                $mail = $sent;
                return true;
            }

            return false;
        });

        $this->assertNotNull($mail, 'Expected reset OTP mail to be sent.');

        return $mail->otp;
    }

    public function test_request_otp_sends_code_for_active_user_and_is_generic(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'user@example.test', 'status' => 'active']);

        $this->postJson('/api/forgot-password', ['email' => 'USER@example.test'])
            ->assertOk()
            ->assertJson(['message' => 'If the account exists, a verification code has been sent.']);

        $record = PasswordResetOtp::query()->where('email', 'user@example.test')->firstOrFail();
        $this->assertNotNull($record->otp_hash);
        $this->assertNotNull($record->expires_at);
        $this->assertSame(0, $record->attempts);
        $this->assertNull($record->reset_token_hash);
        $this->assertNull($record->verified_at);
        $this->assertNotNull($record->last_sent_at);

        $otp = $this->captureOtp('user@example.test');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        $this->assertTrue(Hash::check($otp, $record->otp_hash));
    }

    public function test_request_otp_is_generic_for_unknown_email_and_does_not_store_record(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])
            ->assertOk()
            ->assertJson(['message' => 'If the account exists, a verification code has been sent.']);

        $this->assertDatabaseMissing('password_reset_otps', ['email' => 'missing@example.test']);
        Mail::assertNothingSent();
    }

    public function test_request_otp_suspended_user_returns_generic_and_does_not_send(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'suspended@example.test', 'status' => 'suspended']);

        $this->postJson('/api/forgot-password', ['email' => 'suspended@example.test'])
            ->assertOk()
            ->assertJson(['message' => 'If the account exists, a verification code has been sent.']);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_otps', ['email' => 'suspended@example.test']);
    }

    public function test_verify_otp_returns_reset_token_and_invalidates_otp(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'user@example.test', 'status' => 'active']);
        $this->postJson('/api/forgot-password', ['email' => 'user@example.test'])->assertOk();
        $otp = $this->captureOtp('user@example.test');

        $response = $this->postJson('/api/verify-reset-otp', [
            'email' => 'user@example.test',
            'otp' => $otp,
        ])->assertOk()->assertJsonStructure(['message', 'reset_token']);

        $token = $response->json('reset_token');
        $record = PasswordResetOtp::query()->where('email', 'user@example.test')->firstOrFail();
        $this->assertNull($record->otp_hash);
        $this->assertNotNull($record->verified_at);
        $this->assertTrue(Hash::check($token, $record->reset_token_hash));
        $this->assertNotNull($record->reset_token_expires_at);
    }

    public function test_verify_otp_expiry_rejected(): void
    {
        PasswordResetOtp::query()->create([
            'email' => 'user@example.test',
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
            'attempts' => 0,
        ]);

        $this->postJson('/api/verify-reset-otp', ['email' => 'user@example.test', 'otp' => '123456'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired verification code.']);
    }

    public function test_verify_otp_five_wrong_attempts_then_rejected(): void
    {
        User::factory()->create(['email' => 'user@example.test']);
        $record = PasswordResetOtp::query()->create([
            'email' => 'user@example.test',
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/verify-reset-otp', ['email' => 'user@example.test', 'otp' => '000000'])
                ->assertStatus(422)
                ->assertJson(['message' => 'Invalid or expired verification code.']);
        }

        $this->assertSame(5, $record->fresh()->attempts);

        $this->postJson('/api/verify-reset-otp', ['email' => 'user@example.test', 'otp' => '123456'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many attempts. Request a new code.']);
    }

    public function test_reset_password_rejects_reused_token(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'status' => 'active',
            'password' => 'old-password',
        ]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $otp = $this->captureOtp($user->email);
        $resetToken = $this->postJson('/api/verify-reset-otp', ['email' => $user->email, 'otp' => $otp])
            ->assertOk()->json('reset_token');

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJson(['message' => 'Password reset successfully.']);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422)->assertJson(['message' => 'Invalid or expired password reset session.']);
    }

    public function test_reset_password_revokes_sessions_and_sanctum_and_clears_must_change_password(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function ($table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        Mail::fake();
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'status' => 'active',
            'password' => 'old-password',
            'must_change_password' => true,
        ]);
        $plain = $user->createToken('test-token')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $otp = $this->captureOtp($user->email);
        $resetToken = $this->postJson('/api/verify-reset-otp', ['email' => $user->email, 'otp' => $otp])
            ->assertOk()->json('reset_token');

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJson(['message' => 'Password reset successfully.']);

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($plain));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_otps', ['email' => $user->email]);
    }

    public function test_resend_cooldown_server_enforced_and_generic(): void
    {
        Mail::fake();
        $email = 'user@example.test';
        User::factory()->create(['email' => $email, 'status' => 'active']);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        RateLimiter::clear('resend-reset-otp:'.sha1($email.'|127.0.0.1'));

        $now = now();
        $this->travelTo($now);
        $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();
        Mail::assertSent(ResetPasswordOtpMail::class, 1);
        $record = PasswordResetOtp::query()->where('email', $email)->firstOrFail();
        $firstHash = $record->otp_hash;

        // within 60s cooldown: generic response, no new code stored/sent
        $this->travelTo($now->copy()->addSeconds(30));
        $record->forceFill(['last_sent_at' => now()->subSeconds(30)])->save();
        RateLimiter::clear('resend-reset-otp:'.sha1($email.'|127.0.0.1'));
        $this->postJson('/api/resend-reset-otp', ['email' => $email])
            ->assertOk()
            ->assertJson(['message' => 'If the account exists, a verification code has been sent.']);

        $record->refresh();
        $this->assertSame($firstHash, $record->otp_hash);
        Mail::assertSent(ResetPasswordOtpMail::class, 1);

        // after cooldown window: new code issued
        $this->travelTo($now->copy()->addSeconds(61));
        $record->forceFill(['last_sent_at' => now()->subSeconds(61)])->save();
        RateLimiter::clear('resend-reset-otp:'.sha1($email.'|127.0.0.1'));
        $this->postJson('/api/resend-reset-otp', ['email' => $email])
            ->assertOk()
            ->assertJson(['message' => 'If the account exists, a verification code has been sent.']);

        $record->refresh();
        $this->assertNotSame($firstHash, $record->otp_hash);
        Mail::assertSent(ResetPasswordOtpMail::class, 2);
    }

    public function test_resend_rate_limited_by_email_and_ip_returns_generic(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'user@example.test', 'status' => 'active']);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $key = 'resend-reset-otp:'.sha1('user@example.test|127.0.0.1');
        RateLimiter::clear($key);
        RateLimiter::hit($key, 60);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/resend-reset-otp', ['email' => 'user@example.test'])
            ->assertOk()
            ->assertJson(['message' => 'If the account exists, a verification code has been sent.']);

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_otps', ['email' => 'user@example.test']);
    }
}
