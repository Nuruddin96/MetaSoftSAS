<?php

namespace Tests\Feature\Platform;

use App\Models\Award;
use App\Models\AwardNomination;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Models\PlatformNotification;
use App\Models\Vote;
use App\Models\VoteCampaign;
use App\Models\VoteEntry;
use App\Support\Platform\PlatformSchema;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * Brand & Entrepreneur Recognition Platform end to end: List Your Brand →
 * owner dashboard → Super Admin review → public profile → voting → share
 * link, plus the permission boundaries between visitor, owner and admin.
 */
class BrandPlatformFlowTest extends TestCase
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
            'email' => 'Rina@Example.com',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'terms' => '1',
        ], $overrides));
    }

    private function approvedBrand(string $name = 'Nakshi Ghor', array $attrs = []): Brand
    {
        static $n = 0;
        $n++;
        $phone = '0171'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);
        $owner = BrandOwner::create(['name' => 'Owner '.$n, 'email' => "owner{$n}@example.com", 'phone' => $phone, 'password' => 'secret123']);
        $brand = Brand::create(array_merge([
            'brand_owner_id' => $owner->id, 'name' => $name, 'brand_category_id' => $this->makePlatformCategory('Cat '.$n)->id,
            'founder_name' => 'Owner '.$n, 'phone' => $phone, 'email' => "owner{$n}@example.com",
            'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved', 'approved_at' => now(),
        ], $attrs));
        $brand->assignSlug();
        $brand->save();

        return $brand;
    }

    // --- registration ---------------------------------------------------------------------

    public function test_visitor_sees_registration_form_and_homepage_links_to_it(): void
    {
        $this->makePlatformCategory();

        $this->get(route('owner.register'))->assertOk()->assertSee('List your brand on MetaSoft BD')->assertSee('Optional');
        $this->get('/')->assertOk()->assertSee(route('owner.register'), false);
    }

    public function test_registration_with_only_core_fields_creates_pending_brand_and_logs_owner_in(): void
    {
        $this->register()->assertRedirect(route('owner.dashboard'));

        $owner = BrandOwner::firstOrFail();
        $brand = $owner->brand;
        $this->assertAuthenticatedAs($owner, 'brand_owner');
        $this->assertSame('rina@example.com', $owner->email);
        $this->assertSame('pending', $brand->status);
        $this->assertNull($brand->slug, 'Unreviewed brands get no public URL.');
        $this->assertFalse($brand->is_verified);
        Storage::disk('public')->assertExists($brand->logo_path);
        $this->assertTrue(PlatformNotification::forAdmins()->where('type', 'brand_submitted')->exists());
        $this->assertTrue(PlatformNotification::forOwner($owner->id)->where('type', 'registration_received')->exists());

        $this->get(route('owner.dashboard'))->assertOk()->assertSee('Pending review');
        $this->get(route('brands.index'))->assertOk()->assertDontSee('Nakshi Ghor');
    }

    public function test_registration_requires_core_fields_and_rejects_duplicates(): void
    {
        $this->post(route('owner.register.store'), [])->assertSessionHasErrors(['brand_name', 'logo', 'brand_category_id', 'founder_name', 'phone', 'email', 'division', 'district', 'password', 'terms']);

        $this->approvedBrand('Nakshi  Ghor!');
        $this->register(['brand_name' => 'nakshi ghor'])->assertSessionHasErrors('brand_name');
        $this->register(['brand_name' => 'Other', 'district' => 'Chattogram'])->assertSessionHasErrors('district');
        $this->assertSame(0, BrandOwner::where('email', 'rina@example.com')->count());
    }

    // --- review → public profile ---------------------------------------------------------

    public function test_admin_approval_publishes_brand_with_unique_slug_and_notifies_owner(): void
    {
        $this->approvedBrand('Nakshi Ghor');
        $this->register(['brand_name' => 'Nakshi Ghor BD']);
        $brand = Brand::where('name', 'Nakshi Ghor BD')->firstOrFail();

        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin')->get(route('super.brands.show', $brand))->assertOk()->assertSee('Approve');
        $this->post(route('super.brands.status', $brand), ['action' => 'approve'])->assertRedirect();

        $brand->refresh();
        $this->assertSame('approved', $brand->status);
        $this->assertSame('nakshi-ghor-bd', $brand->slug);
        $this->assertTrue(PlatformNotification::forOwner($brand->brand_owner_id)->where('type', 'brand_approved')->exists());
        $this->assertTrue(PlatformAuditLog::where('action', 'brand.approve')->where('actor_type', 'admin')->exists());

        $this->get('/brand/nakshi-ghor-bd')->assertOk()->assertSee('Nakshi Ghor BD')->assertDontSee('MetaSoft BD verified');
        $this->get(route('brands.index'))->assertSee('Nakshi Ghor BD');
    }

    public function test_duplicate_names_get_distinct_slugs(): void
    {
        $a = $this->approvedBrand('Spice Box');
        $b = Brand::create(['name' => 'Spice-Box', 'founder_name' => 'X', 'phone' => '01799999999', 'email' => 'x@example.com', 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'approved']);
        $b->assignSlug();

        $this->assertSame('spice-box', $a->slug);
        $this->assertSame('spice-box-2', $b->slug);
    }

    public function test_reject_requires_reason_and_owner_can_resubmit(): void
    {
        $this->register();
        $brand = Brand::firstOrFail();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.brands.status', $brand), ['action' => 'reject'])->assertSessionHasErrors('reason');
        $this->post(route('super.brands.status', $brand), ['action' => 'reject', 'reason' => 'Logo is unclear'])->assertRedirect();
        $this->assertSame('rejected', $brand->fresh()->status);

        $this->actingAs($brand->owner, 'brand_owner')->get(route('owner.dashboard'))->assertSee('Logo is unclear');
        $this->post(route('owner.brand.resubmit'))->assertRedirect();
        $this->assertSame('pending', $brand->fresh()->status);
    }

    // --- verification is separate from featured / sponsored / awards ----------------------

    public function test_verification_badge_is_admin_controlled_and_independent_of_sponsorship(): void
    {
        $brand = $this->approvedBrand();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.brands.sponsorship', $brand), ['sponsored' => 1])->assertRedirect();
        $this->post(route('super.brands.featured', $brand), ['featured' => 1])->assertRedirect();
        $brand->refresh();
        $this->assertTrue($brand->is_sponsored);
        $this->assertTrue($brand->is_featured);
        $this->assertFalse($brand->is_verified, 'Paid/editorial placement must never imply verification.');
        $this->assertSame([], $brand->badges(), 'Sponsorship must never create an award badge.');

        $this->get(route('brands.show', $brand->slug))->assertSee('Sponsored')->assertDontSee('MetaSoft BD verified');

        $this->post(route('super.brands.verification', $brand), ['verified' => 1])->assertRedirect();
        $this->assertTrue($brand->fresh()->is_verified);
        $this->get(route('brands.show', $brand->slug))->assertSee('MetaSoft BD verified');
        $this->assertTrue(PlatformNotification::forOwner($brand->brand_owner_id)->where('type', 'verification_changed')->exists());

        $this->post(route('super.brands.verification', $brand), ['verified' => 0, 'reason' => 'Docs expired'])->assertRedirect();
        $this->assertFalse($brand->fresh()->is_verified);
        $this->assertTrue(PlatformAuditLog::where('action', 'brand.unverified')->where('reason', 'Docs expired')->exists());
    }

    // --- owner profile editing ------------------------------------------------------------

    public function test_owner_edits_optional_fields_directly_but_identity_changes_need_review(): void
    {
        $brand = $this->approvedBrand();
        $owner = $brand->owner;

        $base = [
            'name' => $brand->name, 'brand_category_id' => $brand->brand_category_id, 'founder_name' => $brand->founder_name,
            'phone' => $brand->phone, 'email' => $brand->email, 'division' => 'Dhaka', 'district' => 'Dhaka',
        ];
        $this->actingAs($owner, 'brand_owner')->get(route('owner.brand.edit'))->assertOk()->assertSee('Needs review');

        $this->put(route('owner.brand.update'), $base + ['description' => 'Handmade nakshi kantha', 'website' => 'https://nakshi.example'])->assertRedirect();
        $this->assertSame('Handmade nakshi kantha', $brand->fresh()->description);
        $this->assertNull($brand->fresh()->pendingChange);

        $this->put(route('owner.brand.update'), array_merge($base, ['name' => 'Nakshi Ghor Ltd', 'description' => 'Handmade nakshi kantha']))->assertRedirect();
        $this->assertSame('Nakshi Ghor', $brand->fresh()->name, 'Approved identity must not change before review.');
        $change = $brand->fresh()->pendingChange;
        $this->assertSame(['name' => 'Nakshi Ghor Ltd'], $change->changes);

        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin')->get(route('super.brands.changes'))->assertOk()->assertSee('Nakshi Ghor Ltd');
        $this->post(route('super.brands.changes.approve', $change))->assertRedirect();
        $this->assertSame('Nakshi Ghor Ltd', $brand->fresh()->name);
        $this->assertSame('nakshi-ghor', $brand->fresh()->slug, 'The public URL is permanent.');
    }

    public function test_owner_cannot_set_verification_featured_status_or_slug_through_profile_update(): void
    {
        $brand = $this->approvedBrand();
        $this->actingAs($brand->owner, 'brand_owner')->put(route('owner.brand.update'), [
            'name' => $brand->name, 'brand_category_id' => $brand->brand_category_id, 'founder_name' => $brand->founder_name,
            'phone' => $brand->phone, 'email' => $brand->email, 'division' => 'Dhaka', 'district' => 'Dhaka',
            'is_verified' => 1, 'is_featured' => 1, 'is_sponsored' => 1, 'status' => 'approved', 'slug' => 'hijack',
        ]);

        $brand->refresh();
        $this->assertFalse($brand->is_verified);
        $this->assertFalse($brand->is_featured);
        $this->assertFalse($brand->is_sponsored);
        $this->assertSame('nakshi-ghor', $brand->slug);
    }

    public function test_owner_cannot_reach_super_admin_and_guests_cannot_reach_dashboard(): void
    {
        $brand = $this->approvedBrand();

        $this->get(route('owner.dashboard'))->assertRedirect(route('owner.login'));

        $this->actingAs($brand->owner, 'brand_owner');
        $this->get(route('super.brands.index'))->assertRedirect();
        $this->post(route('super.brands.verification', $brand), ['verified' => 1])->assertRedirect();
        $this->assertFalse($brand->fresh()->is_verified);
    }

    public function test_owner_can_only_withdraw_own_nominations(): void
    {
        $mine = $this->approvedBrand('Mine');
        $theirs = $this->approvedBrand('Theirs');
        $award = Award::create(['title' => 'Awards', 'slug' => 'awards-2026', 'year' => 2026, 'status' => 'nominations_open']);
        $cat = $award->categories()->create(['name' => 'Best Brand']);
        $n = AwardNomination::create(['award_id' => $award->id, 'award_category_id' => $cat->id, 'brand_id' => $theirs->id, 'status' => 'submitted']);

        $this->actingAs($mine->owner, 'brand_owner')->post(route('owner.awards.withdraw', $n->id))->assertNotFound();
        $this->assertSame('submitted', $n->fresh()->status);
    }

    public function test_suspended_owner_cannot_log_in(): void
    {
        $brand = $this->approvedBrand();
        $brand->owner->update(['status' => 'suspended']);

        $this->post(route('owner.login.attempt'), ['login' => $brand->owner->email, 'password' => 'secret123'])->assertSessionHasErrors('login');
        $this->assertGuest('brand_owner');
    }

    public function test_owner_logs_in_with_email_or_phone(): void
    {
        $brand = $this->approvedBrand();

        $this->post(route('owner.login.attempt'), ['login' => '+880'.substr($brand->owner->phone, 1), 'password' => 'secret123'])->assertRedirect(route('owner.dashboard'));
        $this->assertAuthenticatedAs($brand->owner, 'brand_owner');
    }

    // --- awards ---------------------------------------------------------------------------

    public function test_award_nomination_flow_keeps_finalist_and_winner_separate(): void
    {
        $brand = $this->approvedBrand();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.awards.store'), ['title' => 'MetaSoft BD Brand Awards', 'year' => 2026, 'status' => 'nominations_open'])->assertRedirect();
        $award = Award::firstOrFail();
        $this->assertSame('metasoft-bd-brand-awards-2026', $award->slug);
        $this->post(route('super.awards.categories.store', $award), ['name' => 'Best Fashion Brand'])->assertRedirect();
        $cat = $award->categories()->firstOrFail();

        $this->actingAs($brand->owner, 'brand_owner')->get(route('owner.awards'))->assertOk()->assertSee('Submit nomination');
        $this->post(route('owner.awards.nominate'), ['award_category_id' => $cat->id])->assertSessionHas('success');
        $this->post(route('owner.awards.nominate'), ['award_category_id' => $cat->id])->assertSessionHas('error');
        $nomination = AwardNomination::firstOrFail();

        $this->actingAs($admin, 'super_admin')->put(route('super.awards.nominations.update', $nomination), ['status' => 'finalist'])->assertRedirect();
        $this->assertTrue(PlatformNotification::forOwner($brand->brand_owner_id)->where('type', 'finalist')->exists());
        $this->assertSame([['type' => 'finalist', 'label' => 'Finalist 2026']], $brand->fresh()->badges());

        $this->post(route('super.awards.recognitions.store', $award), ['type' => 'peoples_choice', 'award_category_id' => $cat->id, 'brand' => $brand->slug])->assertRedirect();
        $types = collect($brand->fresh()->badges())->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['people', 'finalist'], $types);
        $this->assertFalse($brand->fresh()->is_verified);

        $this->get(route('awards.show', $award->slug))->assertOk()->assertSee($brand->name);
    }

    // --- voting ---------------------------------------------------------------------------

    private function openCampaignWith(Brand $brand): VoteEntry
    {
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin')->post(route('super.campaigns.store'), ['title' => 'People’s Choice 2026', 'vote_limit' => 'daily', 'show_counts' => 1])->assertRedirect();
        $campaign = VoteCampaign::firstOrFail();
        $this->post(route('super.campaigns.categories.store', $campaign), ['name' => 'Best Fashion'])->assertRedirect();
        $this->post(route('super.campaigns.entries.store', $campaign), ['vote_category_id' => $campaign->categories()->first()->id, 'brand' => $brand->name])->assertRedirect();
        $this->post(route('super.campaigns.status', $campaign), ['action' => 'start'])->assertRedirect();
        $this->assertTrue($campaign->fresh()->isOpen());
        auth('super_admin')->logout();

        return VoteEntry::firstOrFail();
    }

    private function castVote(Brand $brand, VoteEntry $entry, string $phone, string $device = 'device-a')
    {
        // JSON test requests only carry cookies withCredentials(); browsers' same-origin fetch always does.
        return $this->withCredentials()->withCookie('msbd_vd', $device)->postJson(route('vote.cast', $brand->slug), [
            'entry_id' => $entry->id, 'phone' => $phone, 'form_token' => encrypt(now()->subSeconds(10)->timestamp),
        ]);
    }

    public function test_public_vote_counts_once_per_phone_and_owner_sees_link_and_total(): void
    {
        $brand = $this->approvedBrand();
        $entry = $this->openCampaignWith($brand);
        $this->assertTrue(PlatformNotification::forOwner($brand->brand_owner_id)->where('type', 'voting_started')->exists());

        $this->get(route('vote.show', $brand->slug))->assertOk()->assertSee('Your mobile number');
        $this->castVote($brand, $entry, '01811111111')->assertOk()->assertJson(['ok' => true, 'votes' => 1, 'rank' => 1]);
        $this->castVote($brand, $entry, '+8801811111111', 'device-b')->assertStatus(422);
        $this->assertSame(1, $entry->fresh()->votes_count);

        $vote = Vote::firstOrFail();
        $this->assertSame('018•••••111', $vote->phone_masked);
        $this->assertStringNotContainsString('01811111111', json_encode($vote->getAttributes()));

        $this->actingAs($brand->owner, 'brand_owner')->get(route('owner.voting'))
            ->assertOk()->assertSee(route('vote.show', $brand->slug))->assertSee('Copy link')->assertSee('data-qr', false);
    }

    public function test_share_card_carries_programme_name_badge_flag_and_real_links(): void
    {
        $brand = $this->approvedBrand('Ayat Fashion');
        $page = fn () => $this->actingAs($brand->owner->fresh(), 'brand_owner')->get(route('owner.voting'))->assertOk();

        // No programme set up yet: the configured award name; unverified → no badge.
        $page()->assertSee('data-share-card', false)
            ->assertSee('data-campaign="'.e(config('platform.award_name')).'"', false)
            ->assertSee('data-verified="0"', false)
            ->assertSee('data-url="'.route('vote.show', $brand->slug).'"', false)
            ->assertSee('data-join-url="'.route('owner.register').'"', false)
            ->assertSee('data-badge="'.asset('images/badges/metasoft-verified.png').'"', false);

        // The active programme's own name wins; drafts don't count.
        Award::create(['title' => 'Draft Programme 2027', 'slug' => 'draft-2027', 'year' => 2027, 'status' => 'draft']);
        Award::create(['title' => 'Test Brand Awards 2026', 'slug' => 'tba-2026', 'year' => 2026, 'status' => 'nominations_open']);
        $brand->forceFill(['is_verified' => true, 'verified_at' => now()])->save();
        $page()->assertSee('data-campaign="Test Brand Awards 2026"', false)->assertDontSee('Draft Programme 2027')
            ->assertSee('data-verified="1"', false);
    }

    public function test_bot_guards_reject_instant_or_honeypot_submissions(): void
    {
        $brand = $this->approvedBrand();
        $entry = $this->openCampaignWith($brand);

        $this->postJson(route('vote.cast', $brand->slug), ['entry_id' => $entry->id, 'phone' => '01811111111', 'form_token' => encrypt(now()->timestamp)])->assertStatus(422);
        $this->postJson(route('vote.cast', $brand->slug), ['entry_id' => $entry->id, 'phone' => '01811111112', 'form_token' => encrypt(now()->subSeconds(10)->timestamp), 'website' => 'spam'])->assertStatus(422);
        $this->assertSame(0, $entry->fresh()->votes_count);
    }

    public function test_one_device_cannot_cycle_many_numbers(): void
    {
        config(['platform.voting.device_phone_limit' => 2]);
        $brand = $this->approvedBrand();
        $entry = $this->openCampaignWith($brand);

        $this->castVote($brand, $entry, '01811111111')->assertOk();
        $this->castVote($brand, $entry, '01811111112')->assertOk();
        $this->castVote($brand, $entry, '01811111113')->assertStatus(422);
        $this->assertSame(2, $entry->fresh()->votes_count);
        $this->assertSame('shared_device', Vote::orderByDesc('id')->first()->flags);
    }

    public function test_owner_has_no_route_to_change_votes_and_admin_invalidation_is_audited(): void
    {
        $brand = $this->approvedBrand();
        $entry = $this->openCampaignWith($brand);
        $this->castVote($brand, $entry, '01811111111')->assertOk();
        $this->castVote($brand, $entry, '01811111112', 'device-b')->assertOk();

        $this->actingAs($brand->owner, 'brand_owner');
        $this->post(route('super.campaigns.votes.invalidate', $entry->campaign_id), ['reason' => 'self-serving', 'ids' => [1, 2]])->assertRedirect();
        $this->assertSame(2, $entry->fresh()->votes_count);

        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin')->get(route('super.campaigns.votes', $entry->campaign_id))->assertOk();
        $this->post(route('super.campaigns.votes.invalidate', $entry->campaign_id), ['reason' => 'Fake numbers from one SIM box', 'ids' => [Vote::first()->id]])->assertRedirect();
        $this->assertSame(1, $entry->fresh()->votes_count);
        $log = PlatformAuditLog::where('action', 'vote.invalidated')->firstOrFail();
        $this->assertSame('Platform Admin', $log->actor_name);
        $this->assertSame('Fake numbers from one SIM box', $log->reason);

        $this->post(route('super.campaigns.votes.restore', Vote::first()), ['reason' => 'Verified genuine voter'])->assertRedirect();
        $this->assertSame(2, $entry->fresh()->votes_count);

        $this->get(route('super.campaigns.export', $entry->campaign_id))->assertOk();
    }

    public function test_ended_campaign_rejects_votes(): void
    {
        $brand = $this->approvedBrand();
        $entry = $this->openCampaignWith($brand);
        $entry->campaign->update(['status' => 'ended']);

        $this->castVote($brand, $entry, '01811111111')->assertStatus(422);
        $this->get(route('vote.show', $brand->slug))->assertOk()->assertSee('Voting has closed');
    }

    // --- every management / dashboard page renders ---------------------------------------

    public function test_all_super_admin_platform_pages_render(): void
    {
        $brand = $this->approvedBrand();
        $entry = $this->openCampaignWith($brand);
        $award = Award::create(['title' => 'Awards', 'slug' => 'awards-2026', 'year' => 2026, 'status' => 'draft']);
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin');

        foreach ([
            route('super.brands.index'), route('super.brands.index', ['status' => 'approved', 'flag' => 'verified', 'q' => 'Nak']), route('super.brands.create'),
            route('super.brands.show', $brand), route('super.brands.edit', $brand), route('super.brands.changes'),
            route('super.brand-categories.index'), route('super.awards.index'), route('super.awards.create'),
            route('super.awards.show', $award), route('super.awards.edit', $award), route('super.campaigns.index'),
            route('super.campaigns.create'), route('super.campaigns.show', $entry->campaign_id), route('super.campaigns.edit', $entry->campaign_id),
            route('super.campaigns.votes', $entry->campaign_id), route('super.platform-audit'), route('super.platform-notifications'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_manages_categories_and_adds_brand_manually(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin, 'super_admin')->post(route('super.brand-categories.store'), ['name' => 'Agro & Organic', 'icon' => 'layers', 'color' => '#16A34A'])->assertRedirect();
        $cat = BrandCategory::where('name', 'Agro & Organic')->firstOrFail();

        $this->post(route('super.brands.store'), [
            'name' => 'Krishi Bari', 'brand_category_id' => $cat->id, 'founder_name' => 'Karim', 'phone' => '01912345678',
            'email' => 'k@example.com', 'division' => 'Chattogram', 'district' => "Cox's Bazar", 'status' => 'approved',
        ])->assertRedirect();
        $brand = Brand::where('name', 'Krishi Bari')->firstOrFail();
        $this->assertSame('krishi-bari', $brand->slug);

        $this->delete(route('super.brand-categories.destroy', $cat))->assertRedirect();
        $this->assertFalse($cat->fresh()->is_active, 'A category in use is deactivated, never deleted.');

        $this->post(route('super.brand-categories.reorder'), ['order' => [$cat->id => 5]])->assertRedirect();
        $this->assertSame(5, $cat->fresh()->sort_order);
    }

    public function test_all_owner_pages_render_on_the_owner_guard(): void
    {
        $brand = $this->approvedBrand();
        $this->actingAs($brand->owner, 'brand_owner');

        foreach (['owner.dashboard', 'owner.brand.edit', 'owner.voting', 'owner.awards', 'owner.notifications', 'owner.account'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_platform_pages_show_coming_soon_until_schema_is_imported(): void
    {
        Schema::drop('votes');
        PlatformSchema::flush();

        $this->get(route('brands.index'))->assertStatus(503)->assertSee('almost ready');
    }
}
