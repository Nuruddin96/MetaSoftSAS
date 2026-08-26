<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\NotificationController::preferences()/updatePreferences()
 * — mirrors Tenant\NotificationPreferenceController exactly.
 */
class NotificationPreferenceApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function ($table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('category', 30);
                $table->boolean('enabled')->default(1);
                $table->timestamps();
                $table->unique(['user_id', 'category']);
            });
        }
    }

    public function test_all_categories_default_to_enabled_with_no_stored_rows(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/notifications/preferences')->assertOk();

        $categories = collect($response->json('data'));
        $this->assertCount(8, $categories);
        $this->assertTrue($categories->every(fn (array $c) => $c['enabled'] === true));
        $this->assertFalse($categories->pluck('category')->contains('security'), 'security must never be listed as a togglable row');
    }

    public function test_update_turns_off_every_category_not_included(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/preferences', ['categories' => ['orders', 'messages']])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $response = $this->getJson('/api/mobile/v1/notifications/preferences')->assertOk();
        $categories = collect($response->json('data'))->keyBy('category');

        $this->assertTrue($categories['orders']['enabled']);
        $this->assertTrue($categories['messages']['enabled']);
        $this->assertFalse($categories['stock']['enabled']);
        $this->assertFalse($categories['payments']['enabled']);
    }

    public function test_update_with_an_empty_list_disables_everything_real(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/preferences', ['categories' => []])->assertOk();

        $response = $this->getJson('/api/mobile/v1/notifications/preferences')->assertOk();
        $this->assertTrue(collect($response->json('data'))->every(fn (array $c) => $c['enabled'] === false));
    }

    public function test_security_category_can_never_be_disabled(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        // Even a hostile/careless client submitting only 'security' has no
        // effect: it's not in CATEGORIES, so setEnabled() no-ops for it and
        // it's still not returned as a row (see the preceding test).
        $this->postJson('/api/mobile/v1/notifications/preferences', ['categories' => ['security']])->assertOk();
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $user->id, 'category' => 'security']);
    }

    public function test_preferences_are_scoped_per_user_not_shared_across_the_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $otherUser = $this->makeUser($tenant->id);
        NotificationPreference::create(['user_id' => $otherUser->id, 'category' => 'orders', 'enabled' => false]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/notifications/preferences')->assertOk();
        $categories = collect($response->json('data'))->keyBy('category');

        $this->assertTrue($categories['orders']['enabled'], "another user's disabled preference must not leak");
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/notifications/preferences')->assertUnauthorized();
    }
}
