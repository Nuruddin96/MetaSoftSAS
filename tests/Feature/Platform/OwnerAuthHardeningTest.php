<?php

namespace Tests\Feature\Platform;

use App\Http\Controllers\BrandOwner\PasswordResetController;
use App\Http\Controllers\BrandOwner\RegisterController;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Notifications\BrandOwnerResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Brand owner auth hardening:
 *   - login, registration, forgot-password and reset each have their own
 *     named rate limiter (AppServiceProvider), so activity on one route never
 *     uses up another's allowance; forgot-password and login are also
 *     limited per account across IPs
 *   - mail failure, cross-account tokens, session invalidation after a
 *     password change, concurrent duplicate registration, contact-check input
 */
class OwnerAuthHardeningTest extends TestCase
{
    use InteractsWithPlatformSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
        Storage::fake('public');
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function owner(array $attrs = []): BrandOwner
    {
        return BrandOwner::create(array_merge(['name' => 'Owner', 'email' => 'owner@example.com', 'phone' => '01811111111', 'password' => 'oldpass123'], $attrs));
    }

    private function brandFor(BrandOwner $owner, array $attrs = []): Brand
    {
        $brand = Brand::create(array_merge([
            'brand_owner_id' => $owner->id, 'name' => 'Brand '.$owner->id, 'brand_category_id' => (BrandCategory::first() ?? $this->makePlatformCategory())->id,
            'founder_name' => $owner->name, 'phone' => $owner->phone, 'email' => $owner->email,
            'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now(),
        ], $attrs));
        $brand->assignSlug();
        $brand->save();

        return $brand;
    }

    private function failedLogin(string $login): TestResponse
    {
        return $this->post(route('owner.login.attempt'), ['login' => $login, 'password' => 'wrong-password']);
    }

    private function forgot(string $email): TestResponse
    {
        return $this->post(route('owner.password.email'), ['email' => $email]);
    }

    private function resetAttempt(): TestResponse
    {
        return $this->post(route('owner.password.update'), ['token' => 'nope', 'email' => 'x@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123']);
    }

    private function registerAttempt(): TestResponse
    {
        return $this->post(route('owner.register.store'), []);
    }

    private function tokenFor(string $email): string
    {
        $token = null;
        Notification::assertSentOnDemand(BrandOwnerResetPassword::class, function ($n) use ($email, &$token) {
            if ($n->email === $email) {
                $token = $n->token;
            }

            return $n->email === $email;
        });

        return $token;
    }

    // --- independent rate limits ---------------------------------------------------------

    public function test_exhausting_login_does_not_consume_forgot_password_reset_or_registration(): void
    {
        Notification::fake();
        $this->fromIp('203.0.113.10');

        for ($i = 0; $i < 10; $i++) {
            $this->failedLogin("user{$i}@example.com")->assertStatus(302);
        }
        $this->failedLogin('user99@example.com')->assertStatus(429);

        $this->forgot('someone@example.com')->assertStatus(302)->assertSessionHas('success', PasswordResetController::SENT);
        $this->resetAttempt()->assertStatus(302);
        $this->registerAttempt()->assertStatus(302);
    }

    public function test_exhausting_forgot_password_does_not_block_login_reset_or_registration(): void
    {
        Notification::fake();
        $this->owner();
        $this->fromIp('203.0.113.11');

        for ($i = 0; $i < 5; $i++) {
            $this->forgot("person{$i}@example.com")->assertStatus(302);
        }
        $this->forgot('person9@example.com')->assertStatus(429);

        $this->resetAttempt()->assertStatus(302);
        $this->registerAttempt()->assertStatus(302);
        $this->post(route('owner.login.attempt'), ['login' => 'owner@example.com', 'password' => 'oldpass123'])->assertRedirect(route('owner.dashboard'));
    }

    public function test_registration_and_reset_limits_are_independent_of_each_other(): void
    {
        Notification::fake();
        $this->fromIp('203.0.113.12');

        for ($i = 0; $i < 6; $i++) {
            $this->registerAttempt()->assertStatus(302);
        }
        $this->registerAttempt()->assertStatus(429);
        $this->resetAttempt()->assertStatus(302);

        for ($i = 0; $i < 9; $i++) {
            $this->resetAttempt()->assertStatus(302);
        }
        $this->resetAttempt()->assertStatus(429);

        $this->forgot('a@example.com')->assertStatus(302);
        $this->failedLogin('a@example.com')->assertStatus(302);
    }

    public function test_separate_ips_have_separate_allowances(): void
    {
        Notification::fake();

        $this->fromIp('198.51.100.1');
        for ($i = 0; $i < 5; $i++) {
            $this->forgot("ip1-{$i}@example.com");
        }
        $this->forgot('ip1-x@example.com')->assertStatus(429);
        for ($i = 0; $i < 10; $i++) {
            $this->failedLogin("ip1-{$i}@example.com");
        }
        $this->failedLogin('ip1-y@example.com')->assertStatus(429);

        $this->fromIp('198.51.100.2');
        $this->forgot('ip2@example.com')->assertStatus(302);
        $this->failedLogin('ip2@example.com')->assertStatus(302);
    }

    public function test_people_sharing_one_ip_share_that_ips_allowance(): void
    {
        // e.g. a mobile carrier's shared (CGNAT) address: the per-IP cap is the same as before, no stricter.
        Notification::fake();
        $this->fromIp('100.64.0.1');

        foreach (['a', 'b', 'c', 'd', 'e'] as $who) {
            $this->forgot("{$who}@example.com")->assertStatus(302);
        }
        $this->forgot('f@example.com')->assertStatus(429);
    }

    public function test_forgot_password_for_one_email_is_capped_across_ips_without_revealing_the_account(): void
    {
        Notification::fake();
        $this->owner();

        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $ip) {
            $this->fromIp($ip)->forgot('Owner@Example.com')->assertStatus(302)->assertSessionHas('success', PasswordResetController::SENT);
        }
        $this->fromIp('192.0.2.4')->forgot('owner@example.com')->assertStatus(429);
        // The cap is keyed by the submitted address, so an address with no account is capped identically.
        foreach (['192.0.2.5', '192.0.2.6', '192.0.2.7'] as $ip) {
            $this->fromIp($ip)->forgot('nobody@example.com')->assertStatus(302);
        }
        $this->fromIp('192.0.2.8')->forgot('nobody@example.com')->assertStatus(429);
        // Other addresses from the same IP are unaffected.
        $this->fromIp('192.0.2.4')->forgot('someone-else@example.com')->assertStatus(302);
        // The broker's own once-a-minute rule means only one email actually went out.
        Notification::assertSentOnDemandTimes(BrandOwnerResetPassword::class, 1);
    }

    public function test_login_for_one_account_is_capped_across_ips_and_phone_formats(): void
    {
        $this->owner();
        $formats = ['01811111111', '+8801811111111', '8801811111111', '01811111111', '+880 1811-111111'];
        foreach (range(0, 9) as $i) {
            $this->fromIp('192.0.2.'.(20 + $i))->failedLogin($formats[$i % count($formats)])->assertStatus(302);
        }
        $this->fromIp('192.0.2.40')->failedLogin('01811111111')->assertStatus(429);
        $this->fromIp('192.0.2.41')->failedLogin('other@example.com')->assertStatus(302);
    }

    // --- password reset edge cases --------------------------------------------------------

    public function test_mail_transport_failure_returns_the_generic_answer_and_is_reported(): void
    {
        Exceptions::fake();
        // A real SMTP mailer pointed at a closed local port: the send genuinely fails, nothing leaves the machine.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2, 'encryption' => null, 'username' => null, 'password' => null],
        ]);
        $this->owner();

        $this->from(route('owner.password.request'))->forgot('owner@example.com')
            ->assertRedirect(route('owner.password.request'))
            ->assertSessionHas('success', PasswordResetController::SENT);

        // The fake matches the closure's parameter type exactly, so name the concrete class.
        Exceptions::assertReported(fn (TransportException $e) => str_contains($e->getMessage(), '127.0.0.1:1'));
    }

    public function test_a_valid_token_cannot_reset_a_different_accounts_password(): void
    {
        Notification::fake();
        $a = $this->owner(['email' => 'a@example.com', 'phone' => '01811111111']);
        $b = $this->owner(['email' => 'b@example.com', 'phone' => '01822222222']);
        $this->forgot('a@example.com');
        $tokenA = $this->tokenFor('a@example.com');

        $this->post(route('owner.password.update'), ['token' => $tokenA, 'email' => 'b@example.com', 'password' => 'hijack123', 'password_confirmation' => 'hijack123'])
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);

        $this->assertTrue(Hash::check('oldpass123', $a->fresh()->password));
        $this->assertTrue(Hash::check('oldpass123', $b->fresh()->password));

        // Token A still works, for account A only.
        $this->post(route('owner.password.update'), ['token' => $tokenA, 'email' => 'a@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('owner.login'));
        $this->assertTrue(Hash::check('newpass123', $a->fresh()->password));
        $this->assertTrue(Hash::check('oldpass123', $b->fresh()->password));
    }

    public function test_reset_signs_out_existing_sessions_and_remember_me_devices(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $this->brandFor($owner);

        // "Device 1" is logged in and has visited the dashboard (AuthenticateSession stores the password hash).
        $this->post(route('owner.login.attempt'), ['login' => 'owner@example.com', 'password' => 'oldpass123', 'remember' => '1'])->assertRedirect(route('owner.dashboard'));
        $this->get(route('owner.dashboard'))->assertOk();
        $rememberBefore = $owner->fresh()->remember_token;

        // Elsewhere, the password is reset through the emailed link.
        $this->forgot('owner@example.com');
        $token = $this->tokenFor('owner@example.com');
        $this->app['auth']->forgetGuards();
        $this->post(route('owner.password.update'), ['token' => $token, 'email' => 'owner@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('owner.login'));
        $this->assertNotSame($rememberBefore, $owner->fresh()->remember_token, 'Remember-me tokens are rotated.');

        // Device 1's next request re-reads the owner and is signed out.
        $this->app['auth']->forgetGuards();
        $this->get(route('owner.dashboard'))->assertRedirect(route('owner.login'));
        $this->assertGuest('brand_owner');
    }

    public function test_changing_your_own_password_keeps_the_current_session(): void
    {
        $owner = $this->owner();
        $this->brandFor($owner);
        $this->post(route('owner.login.attempt'), ['login' => 'owner@example.com', 'password' => 'oldpass123'])->assertRedirect(route('owner.dashboard'));
        $this->get(route('owner.dashboard'))->assertOk();

        $this->put(route('owner.account.password'), ['current_password' => 'oldpass123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertSessionHas('success');

        $this->app['auth']->forgetGuards();
        $this->get(route('owner.dashboard'))->assertOk();
    }

    // --- registration race ---------------------------------------------------------------

    public function test_concurrent_duplicate_registration_gets_the_neutral_answer_and_leaves_nothing_behind(): void
    {
        $category = $this->makePlatformCategory();
        // Simulate a parallel request committing the same email after this request's duplicate pre-check passed.
        $raced = false;
        BrandOwner::creating(function () use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('brand_owners')->insert(['name' => 'Parallel', 'email' => 'rina@example.com', 'phone' => '01799999999', 'password' => 'x', 'status' => 'active']);
            }
        });

        $this->post(route('owner.register.store'), [
            'brand_name' => 'Nakshi Ghor', 'logo' => UploadedFile::fake()->image('logo.png', 200, 200), 'brand_category_id' => $category->id,
            'founder_name' => 'Rina Akter', 'phone' => '01712345678', 'email' => 'rina@example.com', 'division' => 'Dhaka', 'district' => 'Gazipur',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => '1',
        ])->assertSessionHasErrors(['account' => RegisterController::ACCOUNT_UNAVAILABLE]);

        $this->assertTrue($raced);
        $this->assertSame(0, BrandOwner::where('phone', '01712345678')->count());
        $this->assertSame(0, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'The uploaded logo is removed.');
        $this->assertGuest('brand_owner');
        $this->assertFalse(PlatformAuditLog::where('action', 'brand.registered')->exists());
    }

    // --- admin contact-check input ---------------------------------------------------------

    public function test_contact_check_rejects_bad_input_and_changes_nothing(): void
    {
        $brand = $this->brandFor($this->owner(), ['status' => 'pending']);
        $this->actingAs($this->makePlatformAdmin(), 'super_admin');

        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1])->assertSessionHasErrors('method');
        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'carrier_pigeon'])->assertSessionHasErrors('method');
        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'phone_call', 'note' => str_repeat('x', 256)])->assertSessionHasErrors('note');
        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => ['phone_call']])->assertSessionHasErrors('method');
        $this->post(route('super.brands.contact-check', $brand), ['checked' => 0, 'method' => 'carrier_pigeon'])->assertSessionHasErrors('method');

        $fresh = $brand->fresh();
        $this->assertNull($fresh->contact_checked_at);
        $this->assertNull($fresh->contact_check_method);
        $this->assertNull($fresh->contact_check_note);
        $this->assertFalse(PlatformAuditLog::where('action', 'like', 'brand.contact_check%')->exists());

        // The longest allowed note is accepted.
        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'phone_call', 'note' => str_repeat('x', 255)])->assertSessionHasNoErrors();
        $this->assertNotNull($brand->fresh()->contact_checked_at);
    }
}
