<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\StoreSetting;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\SettingController::homepage()/updateHomepage()/
 * footer()/updateFooter() — mirrors Tenant\WebsiteController::homepage()/
 * footer() exactly (Website Builder parity task).
 */
class WebsiteHomepageFooterApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_homepage_returns_the_same_defaults_as_the_web_view(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/homepage')->assertOk()
            ->assertJsonPath('hero_style', 'slider')
            ->assertJsonPath('show_categories', true)
            ->assertJsonPath('show_featured', true)
            ->assertJsonPath('featured_title', 'আমাদের প্রোডাক্ট');
    }

    public function test_update_homepage_saves_all_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/homepage', [
            'featured_title' => 'নতুন কালেকশন',
            'hero_style' => 'simple',
            'show_featured' => false,
            'show_categories' => false,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->getJson('/api/mobile/v1/settings/homepage')->assertOk()
            ->assertJsonPath('hero_style', 'simple')
            ->assertJsonPath('show_categories', false)
            ->assertJsonPath('show_featured', false)
            ->assertJsonPath('featured_title', 'নতুন কালেকশন');
    }

    public function test_update_homepage_rejects_an_invalid_hero_style(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/homepage', ['hero_style' => 'carousel'])
            ->assertStatus(422);
    }

    public function test_update_homepage_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        Sanctum::actingAs($userA);
        $this->postJson('/api/mobile/v1/settings/homepage', ['featured_title' => 'A only'])->assertOk();

        app()->instance('currentTenant', $tenantB);
        $this->assertNull(StoreSetting::where('key', 'featured_title')->first());
        app()->forgetInstance('currentTenant');
    }

    public function test_footer_defaults_the_phone_number_from_the_tenant_owner_phone(): void
    {
        $tenant = $this->makeTenant(['owner_phone' => '01711112222']);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/footer')->assertOk()
            ->assertJsonPath('footer_phone', '01711112222')
            ->assertJsonPath('footer_about', null)
            ->assertJsonPath('show_whatsapp_float', false);
    }

    public function test_update_footer_saves_text_social_and_whatsapp_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/footer', [
            'footer_about' => 'আমরা মানসম্মত পণ্য বিক্রি করি।',
            'footer_phone' => '01899998888',
            'footer_email' => 'shop@example.com',
            'footer_address' => 'Dhaka, Bangladesh',
            'footer_note' => 'সকল পণ্যে ৭ দিনের রিটার্ন সুবিধা',
            'social_facebook' => 'https://facebook.com/myshop',
            'whatsapp_number' => '8801712345678',
            'show_whatsapp_float' => true,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->getJson('/api/mobile/v1/settings/footer')->assertOk()
            ->assertJsonPath('footer_about', 'আমরা মানসম্মত পণ্য বিক্রি করি।')
            ->assertJsonPath('footer_phone', '01899998888')
            ->assertJsonPath('social_facebook', 'https://facebook.com/myshop')
            ->assertJsonPath('whatsapp_number', '8801712345678')
            ->assertJsonPath('show_whatsapp_float', true);
    }

    public function test_update_footer_rejects_an_invalid_social_url(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/footer', ['social_facebook' => 'not-a-url'])
            ->assertStatus(422);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/homepage')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/homepage', [])->assertUnauthorized();
        $this->getJson('/api/mobile/v1/settings/footer')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/footer', [])->assertUnauthorized();
    }
}
