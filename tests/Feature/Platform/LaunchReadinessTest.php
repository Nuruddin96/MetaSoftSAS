<?php

namespace Tests\Feature\Platform;

use App\Http\Controllers\BrandOwner\PasswordResetController;
use App\Http\Controllers\BrandOwner\RegisterController;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Notifications\BrandOwnerResetPassword;
use App\Support\Platform\PlatformSchema;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Launch-readiness hardening (database/sql/chunk66.sql):
 *   - registration never reveals whether an email/phone already has an account
 *   - brand owner self-service password reset on its own broker/table
 *   - Super Admin "contact checked" note (manual, never OTP, never Verified)
 *   - Verified badge records and publishes exactly the evidence reviewed
 */
class LaunchReadinessTest extends TestCase
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

    private function register(array $overrides = []): TestResponse
    {
        $category = BrandCategory::first() ?? $this->makePlatformCategory();

        return $this->post(route('owner.register.store'), array_merge([
            'brand_name' => 'Nakshi Ghor',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'brand_category_id' => $category->id,
            'founder_name' => 'Rina Akter',
            'phone' => '01712345678',
            'email' => 'rina@example.com',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'terms' => '1',
        ], $overrides));
    }

    private function owner(array $attrs = []): BrandOwner
    {
        return BrandOwner::create(array_merge(['name' => 'Existing Owner', 'email' => 'owner@example.com', 'phone' => '01811111111', 'password' => 'oldpass123'], $attrs));
    }

    private function brandFor(BrandOwner $owner, array $attrs = []): Brand
    {
        $brand = Brand::create(array_merge([
            'brand_owner_id' => $owner->id, 'name' => 'Owner Brand '.$owner->id, 'brand_category_id' => (BrandCategory::first() ?? $this->makePlatformCategory())->id,
            'founder_name' => $owner->name, 'phone' => $owner->phone, 'email' => $owner->email,
            'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now(),
        ], $attrs));
        if ($brand->status === 'approved') {
            $brand->assignSlug();
            $brand->save();
        }

        return $brand;
    }

    // --- registration: no account enumeration --------------------------------------------

    public function test_duplicate_email_and_duplicate_phone_get_the_same_neutral_answer(): void
    {
        $this->owner(['email' => 'taken@example.com', 'phone' => '01911111111']);

        $byEmail = $this->register(['email' => 'TAKEN@example.com', 'brand_name' => 'Brand A']);
        $byPhone = $this->register(['phone' => '+8801911111111', 'brand_name' => 'Brand B']);

        foreach ([$byEmail, $byPhone] as $response) {
            $response->assertSessionHasErrors(['account' => RegisterController::ACCOUNT_UNAVAILABLE])
                ->assertSessionDoesntHaveErrors(['email', 'phone']);
        }
        $this->assertSame(1, BrandOwner::count());
        $this->assertSame(0, Brand::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'No logo is stored for a refused registration.');

        $this->from(route('owner.register'))->followingRedirects()->register(['email' => 'taken@example.com'])
            ->assertSee(RegisterController::ACCOUNT_UNAVAILABLE)
            ->assertDontSee('already exists')
            ->assertSee(route('owner.password.request'), false);
    }

    public function test_field_validation_and_honeypot_still_apply(): void
    {
        $this->register(['phone' => '12345'])->assertSessionHasErrors('phone')->assertSessionDoesntHaveErrors('account');
        $this->register(['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->register(['password_confirmation' => 'different'])->assertSessionHasErrors('password');

        $this->register(['company_website' => 'http://spam.example'])->assertRedirect(route('owner.register'));
        $this->assertSame(0, BrandOwner::count());
    }

    public function test_successful_registration_is_audited_and_logs_the_owner_in(): void
    {
        $this->register(['email' => 'New@Example.com'])->assertRedirect(route('owner.dashboard'));

        $owner = BrandOwner::firstOrFail();
        $this->assertSame('new@example.com', $owner->email);
        $this->assertAuthenticatedAs($owner, 'brand_owner');
        $this->assertTrue(PlatformAuditLog::where('action', 'brand.registered')->exists());
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('owner.register.store'), [])->assertStatus(302);
        }
        $this->post(route('owner.register.store'), [])->assertStatus(429);
    }

    // --- owner password reset ------------------------------------------------------------

    public function test_reset_request_answers_the_same_for_known_unknown_and_suspended_emails(): void
    {
        Notification::fake();
        $this->owner(['email' => 'suspended@example.com', 'phone' => '01822222222', 'status' => 'suspended']);

        foreach (['nobody@example.com', 'suspended@example.com'] as $email) {
            $this->from(route('owner.password.request'))->post(route('owner.password.email'), ['email' => $email])
                ->assertRedirect(route('owner.password.request'))->assertSessionHas('success', PasswordResetController::SENT);
        }
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('brand_owner_password_reset_tokens')->count());

        $this->owner();
        $this->from(route('owner.password.request'))->post(route('owner.password.email'), ['email' => 'Owner@Example.com'])
            ->assertSessionHas('success', PasswordResetController::SENT);

        Notification::assertSentOnDemand(BrandOwnerResetPassword::class, function ($n, $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === 'owner@example.com'
                && str_contains($n->toMail($notifiable)->actionUrl, '/brand-owner/reset-password/');
        });
        $this->assertSame(1, DB::table('brand_owner_password_reset_tokens')->where('email', 'owner@example.com')->count());
    }

    public function test_full_reset_flow_changes_password_once_and_token_is_single_use(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $this->brandFor($owner);

        $this->post(route('owner.password.email'), ['email' => 'owner@example.com']);
        $token = null;
        Notification::assertSentOnDemand(BrandOwnerResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $stored = DB::table('brand_owner_password_reset_tokens')->value('token');
        $this->assertNotSame($token, $stored, 'Tokens are stored hashed, never in plain text.');

        $this->get(route('owner.password.reset', $token).'?email=owner@example.com')->assertOk()->assertSee('Choose a new password');

        $this->post(route('owner.password.update'), ['token' => $token, 'email' => 'owner@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertRedirect(route('owner.login'));
        $this->assertTrue(Hash::check('newpass123', $owner->fresh()->password));
        $this->assertSame(0, DB::table('brand_owner_password_reset_tokens')->count());
        $this->assertTrue(PlatformAuditLog::where('action', 'owner.password_self_reset')->exists());

        // Replaying the same link fails.
        $this->from(route('owner.password.reset', $token))->post(route('owner.password.update'), ['token' => $token, 'email' => 'owner@example.com', 'password' => 'another123', 'password_confirmation' => 'another123'])
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);
        $this->assertTrue(Hash::check('newpass123', $owner->fresh()->password));

        $this->post(route('owner.login.attempt'), ['login' => 'owner@example.com', 'password' => 'newpass123'])->assertRedirect(route('owner.dashboard'));
    }

    public function test_wrong_or_expired_tokens_are_rejected(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $this->post(route('owner.password.email'), ['email' => 'owner@example.com']);
        $token = null;
        Notification::assertSentOnDemand(BrandOwnerResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post(route('owner.password.update'), ['token' => 'wrong-token', 'email' => 'owner@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);

        $this->travel(61)->minutes();
        $this->post(route('owner.password.update'), ['token' => $token, 'email' => 'owner@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertSessionHasErrors(['email' => PasswordResetController::INVALID]);
        $this->assertTrue(Hash::check('oldpass123', $owner->fresh()->password));
    }

    public function test_reset_uses_its_own_broker_and_table_not_the_tenant_one(): void
    {
        $this->assertSame('brand_owners', config('auth.passwords.brand_owners.provider'));
        $this->assertSame('brand_owner_password_reset_tokens', config('auth.passwords.brand_owners.table'));
        $this->assertSame(BrandOwner::class, config('auth.providers.brand_owners.model'));
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'), 'Tenant resets are untouched.');
    }

    public function test_repeat_requests_are_throttled(): void
    {
        Notification::fake();
        $this->owner();

        $this->post(route('owner.password.email'), ['email' => 'owner@example.com']);
        $this->post(route('owner.password.email'), ['email' => 'owner@example.com'])->assertSessionHas('success', PasswordResetController::SENT);
        Notification::assertSentOnDemandTimes(BrandOwnerResetPassword::class, 1);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('owner.password.email'), ['email' => 'x'.$i.'@example.com']);
        }
        $this->post(route('owner.password.email'), ['email' => 'y@example.com'])->assertStatus(429);
    }

    public function test_login_links_to_reset_and_reset_falls_back_to_support_before_chunk66_import(): void
    {
        $this->get(route('owner.login'))->assertOk()->assertSee(route('owner.password.request'), false);
        $this->get(route('owner.password.request'))->assertOk()->assertSee('Send reset link');

        Schema::drop('brand_owner_password_reset_tokens');
        PlatformSchema::flush();
        Notification::fake();
        $this->owner();

        $this->get(route('owner.login'))->assertOk()->assertDontSee(route('owner.password.request'), false)->assertSee('Contact support on WhatsApp');
        $this->get(route('owner.password.request'))->assertOk()->assertSee('not available yet')->assertDontSee('Send reset link');
        $this->post(route('owner.password.email'), ['email' => 'owner@example.com'])->assertRedirect(route('owner.password.request'));
        Notification::assertNothingSent();
    }

    // --- admin contact check -------------------------------------------------------------

    public function test_admin_records_and_clears_a_contact_check_with_audit_and_no_verification(): void
    {
        $brand = $this->brandFor($this->owner(), ['status' => 'pending']);
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin, 'super_admin')->get(route('super.brands.show', $brand))->assertOk()
            ->assertSee('Owner contact check')->assertSee('Not checked yet.')->assertSee('not</strong> automated (OTP)', false)
            ->assertSee('has not been checked yet');

        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1])->assertSessionHasErrors('method');
        $this->assertNull($brand->fresh()->contact_checked_at);

        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'whatsapp', 'note' => 'Owner confirmed by WhatsApp call'])->assertRedirect();
        $fresh = $brand->fresh();
        $this->assertNotNull($fresh->contact_checked_at);
        $this->assertSame($admin->id, (int) $fresh->contact_checked_by);
        $this->assertSame('whatsapp', $fresh->contact_check_method);
        $this->assertFalse($fresh->is_verified, 'A contact check never grants the Verified badge.');
        $log = PlatformAuditLog::where('action', 'brand.contact_checked')->firstOrFail();
        $this->assertSame('admin', $log->actor_type);
        $this->assertStringNotContainsString('01811111111', json_encode($log->changes), 'The audit log stores a masked number.');

        $this->get(route('super.brands.show', $brand))->assertSee('Contact checked — WhatsApp message or call')->assertSee($admin->name);

        $this->post(route('super.brands.status', $brand), ['action' => 'approve'])->assertRedirect();
        $approve = PlatformAuditLog::where('action', 'brand.approve')->firstOrFail();
        $this->assertTrue($approve->changes['contact_checked']);

        $this->post(route('super.brands.contact-check', $brand), ['checked' => 0])->assertRedirect();
        $this->assertNull($brand->fresh()->contact_checked_at);
        $this->assertTrue(PlatformAuditLog::where('action', 'brand.contact_check_cleared')->exists());
    }

    public function test_approval_without_contact_check_is_allowed_but_logged(): void
    {
        $brand = $this->brandFor($this->owner(), ['status' => 'pending']);
        $this->actingAs($this->makePlatformAdmin(), 'super_admin')->post(route('super.brands.status', $brand), ['action' => 'approve'])->assertRedirect();

        $this->assertSame('approved', $brand->fresh()->status);
        $this->assertFalse(PlatformAuditLog::where('action', 'brand.approve')->firstOrFail()->changes['contact_checked']);
    }

    public function test_owners_and_guests_cannot_record_contact_checks_or_verify(): void
    {
        $owner = $this->owner();
        $brand = $this->brandFor($owner);

        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'phone_call'])->assertRedirect();
        $this->actingAs($owner, 'brand_owner')->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'phone_call'])->assertRedirect();
        $this->post(route('super.brands.verification', $brand), ['verified' => 1, 'evidence' => ['trade_licence']])->assertRedirect();

        $fresh = $brand->fresh();
        $this->assertNull($fresh->contact_checked_at);
        $this->assertFalse($fresh->is_verified);

        // The owner profile form ignores protected fields.
        $this->put(route('owner.brand.update'), [
            'name' => $brand->name, 'brand_category_id' => $brand->brand_category_id, 'founder_name' => $brand->founder_name, 'phone' => $brand->phone,
            'email' => $brand->email, 'division' => 'Dhaka', 'district' => 'Dhaka',
            'is_verified' => 1, 'verification_basis' => ['trade_licence'], 'contact_checked_at' => now()->toDateTimeString(),
        ])->assertRedirect();
        $fresh = $brand->fresh();
        $this->assertFalse($fresh->is_verified);
        $this->assertNull($fresh->verification_basis);
        $this->assertNull($fresh->contact_checked_at);
    }

    public function test_contact_check_needs_chunk66_and_approval_still_works_without_it(): void
    {
        Schema::table('brands', fn ($t) => $t->dropColumn(['contact_checked_at', 'contact_checked_by', 'contact_check_method', 'contact_check_note', 'verification_basis']));
        PlatformSchema::flush();
        $brand = $this->brandFor($this->owner(), ['status' => 'pending']);

        $this->actingAs($this->makePlatformAdmin(), 'super_admin')->get(route('super.brands.show', $brand))->assertOk()->assertSee('chunk66.sql');
        $this->post(route('super.brands.contact-check', $brand), ['checked' => 1, 'method' => 'phone_call'])->assertSessionHas('error');
        $this->post(route('super.brands.status', $brand), ['action' => 'approve'])->assertRedirect();
        $this->assertSame('approved', $brand->fresh()->status);

        // Verification keeps working (no evidence storage until the import).
        $this->post(route('super.brands.verification', $brand), ['verified' => 1])->assertRedirect();
        $this->assertTrue($brand->fresh()->is_verified);
    }

    // --- Verified badge transparency ------------------------------------------------------

    public function test_granting_verification_requires_and_publishes_the_reviewed_evidence(): void
    {
        $brand = $this->brandFor($this->owner());
        $this->actingAs($this->makePlatformAdmin(), 'super_admin');

        $this->post(route('super.brands.verification', $brand), ['verified' => 1])->assertSessionHasErrors('evidence');
        $this->post(route('super.brands.verification', $brand), ['verified' => 1, 'evidence' => ['made_up']])->assertSessionHasErrors('evidence.0');
        $this->assertFalse($brand->fresh()->is_verified);

        $this->post(route('super.brands.verification', $brand), ['verified' => 1, 'evidence' => ['trade_licence', 'contact']])->assertRedirect();
        $fresh = $brand->fresh();
        $this->assertTrue($fresh->is_verified);
        $this->assertSame(['trade_licence', 'contact'], $fresh->verification_basis);
        $this->assertSame(['trade_licence', 'contact'], PlatformAuditLog::where('action', 'brand.verified')->firstOrFail()->changes['evidence']);

        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('MetaSoft BD Verified')->assertSee('Our team reviewed:')
            ->assertSee(Brand::VERIFICATION_EVIDENCE['trade_licence'])->assertSee(Brand::VERIFICATION_EVIDENCE['contact'])
            ->assertDontSee(Brand::VERIFICATION_EVIDENCE['owner_id'])->assertDontSee(Brand::VERIFICATION_EVIDENCE['tin_bin'])
            ->assertDontSee('identity and contact details');

        $this->post(route('super.brands.verification', $brand), ['verified' => 0])->assertRedirect();
        $this->assertNull($brand->fresh()->verification_basis);
        $this->get(route('brands.show', $brand->slug))->assertDontSee('Our team reviewed:')->assertSee('does not carry the Verified badge');
    }

    public function test_brands_verified_before_evidence_recording_claim_no_specific_evidence(): void
    {
        $brand = $this->brandFor($this->owner(), ['is_verified' => true, 'verified_at' => now()]);

        $this->get(route('brands.show', $brand->slug))->assertOk()
            ->assertSee('the specific evidence reviewed was not recorded')
            ->assertDontSee('Our team reviewed:');
        foreach (Brand::VERIFICATION_EVIDENCE as $label) {
            $this->get(route('brands.show', $brand->slug))->assertDontSee($label);
        }
        $this->assertTrue($brand->fresh()->is_verified, 'Existing verification states are untouched.');
    }
}
