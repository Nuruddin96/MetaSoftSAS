<?php

namespace Tests\Feature\Facebook;

use App\Models\CourierSetting;
use App\Models\Tenant;
use App\Models\User;

/**
 * The Settings page's courier cards must show a "already saved" placeholder
 * hint per-field so staff (and anyone auditing the account) can tell which
 * credentials are actually configured without ever seeing the value itself.
 * Before this, only Steadfast's api_key field had this — secret_key (and
 * every Pathao field) always rendered a plain placeholder regardless of
 * whether a value was saved, making a half-configured integration
 * (api_key set, secret_key blank) visually indistinguishable from a fully
 * configured one. See the live-audit finding this fixes.
 */
class SettingsCourierCredentialDisplayTest extends FacebookFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function loginAndGetSettings(Tenant $tenant, User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'settings'));
    }

    public function test_steadfast_secret_key_shows_saved_indicator_only_when_a_value_is_actually_present(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        // api_key set, secret_key deliberately left out — exactly the
        // half-configured state the live audit found and this fixes.
        CourierSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'real-secret-api-key-value'],
            'is_active' => true,
        ]);

        $response = $this->loginAndGetSettings($tenant, $user);

        $response->assertOk();
        $response->assertSee('API Key (সেভ করা আছে');
        $response->assertDontSee('Secret Key (সেভ করা আছে');
        $response->assertDontSee('real-secret-api-key-value', false);
    }

    public function test_steadfast_secret_key_shows_saved_indicator_when_present(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        CourierSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'key-value', 'secret_key' => 'super-secret-value'],
            'is_active' => true,
        ]);

        $response = $this->loginAndGetSettings($tenant, $user);

        $response->assertOk();
        $response->assertSee('API Key (সেভ করা আছে');
        $response->assertSee('Secret Key (সেভ করা আছে');
        $response->assertDontSee('super-secret-value', false);
        $response->assertDontSee('key-value', false);
    }

    public function test_steadfast_shows_no_saved_indicators_when_not_configured_at_all(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->loginAndGetSettings($tenant, $user);

        $response->assertOk();
        $response->assertDontSee('API Key (সেভ করা আছে');
        $response->assertDontSee('Secret Key (সেভ করা আছে');
    }

    public function test_pathao_shows_saved_indicator_only_for_fields_that_have_a_value(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        // Partial Pathao config: client_id and username set, the rest blank.
        CourierSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'pathao',
            'credentials' => ['client_id' => 'pathao-client-id', 'username' => 'merchant@example.com'],
            'is_active' => false,
        ]);

        $response = $this->loginAndGetSettings($tenant, $user);

        $response->assertOk();
        $response->assertSee('Client ID (সেভ করা আছে');
        $response->assertSee('Merchant Email (সেভ করা আছে');
        $response->assertDontSee('Client Secret (সেভ করা আছে');
        $response->assertDontSee('Merchant Password (সেভ করা আছে');
        $response->assertDontSee('Store ID (সেভ করা আছে');
        $response->assertDontSee('pathao-client-id', false);
        $response->assertDontSee('merchant@example.com', false);
    }

    public function test_pathao_shows_saved_indicators_for_all_fields_when_fully_configured(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        CourierSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'pathao',
            'credentials' => [
                'client_id' => 'cid', 'client_secret' => 'csecret',
                'username' => 'u@example.com', 'password' => 'ppass', 'store_id' => 'store-1',
            ],
            'is_active' => true,
        ]);

        $response = $this->loginAndGetSettings($tenant, $user);

        $response->assertOk();
        foreach (['Client ID', 'Client Secret', 'Merchant Email', 'Merchant Password', 'Store ID'] as $label) {
            $response->assertSee($label.' (সেভ করা আছে');
        }
        $response->assertDontSee('csecret', false);
        $response->assertDontSee('ppass', false);
    }

    public function test_courier_credentials_never_leak_across_tenants_on_the_settings_page(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        app()->instance('currentTenant', $tenantA);
        CourierSetting::create([
            'tenant_id' => $tenantA->id,
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'tenant-a-key', 'secret_key' => 'tenant-a-secret'],
            'is_active' => true,
        ]);

        $response = $this->loginAndGetSettings($tenantB, $userB);

        $response->assertOk();
        // Tenant B has no steadfast row of its own — neither field should
        // show a saved indicator, and tenant A's values must never appear.
        $response->assertDontSee('API Key (সেভ করা আছে');
        $response->assertDontSee('Secret Key (সেভ করা আছে');
        $response->assertDontSee('tenant-a-key', false);
        $response->assertDontSee('tenant-a-secret', false);
    }
}
