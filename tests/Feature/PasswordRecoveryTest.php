<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        config(['app.url' => 'https://cms.example.test']);
    }

    private function account(string $email = 'owner@example.test', bool $active = true): User
    {
        return User::create(['name' => 'Recovery owner', 'email' => $email, 'password' => 'Original-password-123', 'role' => 'owner', 'is_active' => $active]);
    }

    private function payload(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'Replacement-password-456', 'password_confirmation' => 'Replacement-password-456'];
    }

    public function test_active_account_receives_hashed_token_and_trusted_origin_link(): void
    {
        $user = $this->account();
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example.test'])->postJson('/api/v1/password/forgot', ['email' => $user->email])
            ->assertOk()->assertJsonStructure(['message'])->assertJsonMissingPath('token');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();
            $this->assertNotSame($notification->token, $stored->token);
            $this->assertTrue(Hash::check($notification->token, $stored->token));
            $url = $notification->toMail($user)->actionUrl;
            $this->assertSame('cms.example.test', parse_url($url, PHP_URL_HOST));
            $this->assertSame('/studio/reset-password', parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame(['token' => $notification->token, 'email' => $user->email], $query);
            return true;
        });
    }

    public function test_unknown_inactive_and_throttled_accounts_have_identical_response(): void
    {
        $active = $this->account();
        $inactive = $this->account('inactive@example.test', false);
        $responses = [];
        foreach ([$active->email, 'unknown@example.test', $inactive->email, $active->email] as $email) {
            $responses[] = $this->postJson('/api/v1/password/forgot', compact('email'))->assertOk()->json();
        }
        foreach ($responses as $response) $this->assertSame($responses[0], $response);
        Notification::assertSentToTimes($active, ResetPassword::class, 1);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_delivery_failure_is_generic_and_does_not_leave_a_token(): void
    {
        $user = $this->account();
        $tokenWasCreated = false;
        Notification::shouldReceive('send')->once()->andReturnUsing(function () use (&$tokenWasCreated) {
            $tokenWasCreated = DB::table('password_reset_tokens')->count() === 1;
            throw new \RuntimeException('Transport unavailable');
        });
        $this->postJson('/api/v1/password/forgot', ['email' => $user->email])->assertOk()->assertJsonMissingPath('token');
        $this->assertTrue($tokenWasCreated);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_changes_password_consumes_token_rotates_remember_token_and_audits(): void
    {
        $user = $this->account();
        $user->setRememberToken('previous-remember-token');
        $user->save();
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertOk()->assertJsonStructure(['message', 'csrf_token']);
        $this->assertGuest();
        $this->assertTrue(Hash::check('Replacement-password-456', $user->fresh()->password));
        $this->assertNotSame('previous-remember-token', $user->fresh()->getRememberToken());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.password_reset', 'user_id' => $user->id, 'context' => null]);
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertUnprocessable();
        $this->assertDatabaseCount('audit_events', 1);
        $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'Original-password-123'])->assertUnprocessable();
        $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'Replacement-password-456'])->assertOk();
    }

    public function test_expired_wrong_email_wrong_token_and_inactive_reset_links_are_rejected(): void
    {
        $user = $this->account();
        $token = Password::broker('users')->createToken($user);
        $wrong = $this->postJson('/api/v1/password/reset', $this->payload($user, 'wrong-token'))->assertUnprocessable()->json('errors.email');
        $this->postJson('/api/v1/password/reset', [...$this->payload($user, $token), 'email' => 'unknown@example.test'])->assertUnprocessable()->assertJsonPath('errors.email', $wrong);
        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertUnprocessable()->assertJsonPath('errors.email', $wrong);
        $user->update(['is_active' => true]);
        $this->travel(61)->minutes();
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertUnprocessable()->assertJsonPath('errors.email', $wrong);
        $this->assertTrue(Hash::check('Original-password-123', $user->fresh()->password));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_newly_issued_link_invalidates_previous_link(): void
    {
        $user = $this->account();
        $first = Password::broker('users')->createToken($user);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/password/forgot', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/password/reset', $this->payload($user, $first))->assertUnprocessable();
        $notification = Notification::sent($user, ResetPassword::class)->first();
        $this->postJson('/api/v1/password/reset', $this->payload($user, $notification->token))->assertOk();
    }

    public function test_invalid_passwords_do_not_consume_the_link(): void
    {
        $user = $this->account();
        $token = Password::broker('users')->createToken($user);
        foreach ([['short', 'short'], [str_repeat('a', 73), str_repeat('a', 73)], [str_repeat('😀', 24), str_repeat('😀', 24)], ['Replacement-password-456', 'different-password']] as [$password, $confirmation]) {
            $this->postJson('/api/v1/password/reset', [...$this->payload($user, $token), 'password' => $password, 'password_confirmation' => $confirmation])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertOk();
    }

    public function test_endpoints_validate_inputs_and_limit_requests_without_account_lookup_leaks(): void
    {
        $this->postJson('/api/v1/password/forgot', ['email' => ['invalid']])->assertUnprocessable();
        $this->postJson('/api/v1/password/reset', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'token', 'password']);
        Cache::flush();
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v1/password/forgot', ['email' => 'missing@example.test'])->assertOk();
        $this->postJson('/api/v1/password/forgot', ['email' => 'missing@example.test'])->assertTooManyRequests();
        Cache::flush();
        for ($i = 0; $i < 10; $i++) $this->postJson('/api/v1/password/reset', [])->assertUnprocessable();
        $this->postJson('/api/v1/password/reset', [])->assertTooManyRequests();
    }

    public function test_recovery_pages_and_endpoints_are_private_and_do_not_send_referrers(): void
    {
        // This checks HTTP policy; built assets are exercised by the browser suite.
        $this->withoutVite();
        foreach (['/studio/reset-password?token=example&email=test%40example.test', '/studio/forgot-password'] as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->postJson('/api/v1/password/forgot', ['email' => 'missing@example.test'])->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_password_reset_invalidates_previous_session_and_legacy_sessions(): void
    {
        $user = $this->account();
        $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'Original-password-123'])->assertOk();
        $session = session()->all();
        $this->assertArrayHasKey('password_hash_web', $session);
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertOk();
        Auth::forgetGuards();
        $this->withSession($session)->getJson('/api/v1/studio/pages')->assertUnauthorized();
        Auth::forgetGuards();
        $this->flushSession();
        $legacy = [Auth::guard()->getName() => $user->id];
        $this->withSession($legacy)->getJson('/api/v1/studio/pages')->assertUnauthorized();
    }

    public function test_reset_does_not_revoke_another_accounts_session(): void
    {
        $user = $this->account();
        $other = $this->account('other@example.test');
        $this->postJson('/api/v1/session', ['email' => $other->email, 'password' => 'Original-password-123'])->assertOk();
        $session = session()->all();
        $this->flushSession();
        Auth::forgetGuards();
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/v1/password/reset', $this->payload($user, $token))->assertOk();
        Auth::forgetGuards();
        $this->withSession($session)->getJson('/api/v1/studio/pages')->assertOk();
    }
}
