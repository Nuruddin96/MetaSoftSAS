<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithPushSchema;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use InteractsWithPushSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPushSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl($tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    private function subscriptionPayload(string $endpoint = 'https://push.example/abc123'): array
    {
        return [
            'subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => 'test-p256dh-key', 'auth' => 'test-auth-key'],
            ],
            'device_name' => 'Test Phone',
        ];
    }

    public function test_store_creates_a_subscription_scoped_to_the_authenticated_user_and_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->postJson($this->panelUrl($tenant, 'push/subscribe'), $this->subscriptionPayload());

        $response->assertOk()->assertJson(['status' => 'subscribed']);

        $this->assertDatabaseHas('push_subscriptions', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', 'https://push.example/abc123'),
            'is_active' => 1,
        ]);
    }

    public function test_subscribing_the_same_endpoint_twice_updates_rather_than_duplicates(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $endpoint = 'https://push.example/same-endpoint';

        $this->actingAs($user, 'tenant')->postJson($this->panelUrl($tenant, 'push/subscribe'), $this->subscriptionPayload($endpoint));
        $this->actingAs($user, 'tenant')->postJson($this->panelUrl($tenant, 'push/subscribe'), $this->subscriptionPayload($endpoint));

        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->where('endpoint_hash', hash('sha256', $endpoint))->count());
    }

    public function test_destroy_deactivates_only_the_authenticated_users_own_subscription(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant->id);
        $otherStaff = $this->makeUser($tenant->id);
        $endpoint = 'https://push.example/owner-device';

        $ownerSub = PushSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh_key' => 'k', 'auth_key' => 'a',
            'is_active' => 1,
        ]);

        // A different user in the same tenant must NOT be able to
        // deactivate someone else's subscription by guessing the endpoint.
        $this->actingAs($otherStaff, 'tenant')
            ->postJson($this->panelUrl($tenant, 'push/unsubscribe'), ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertTrue($ownerSub->fresh()->is_active, 'another user\'s unsubscribe call must not deactivate the owner\'s own subscription');

        $this->actingAs($owner, 'tenant')
            ->postJson($this->panelUrl($tenant, 'push/unsubscribe'), ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertFalse($ownerSub->fresh()->is_active, 'the owner unsubscribing their own endpoint must deactivate it');
    }

    /**
     * B.1 regression: the same physical browser endpoint must be usable
     * independently by two different tenants (e.g. one person managing two
     * MetaSoft shops from the same browser profile) — see chunk31.sql's
     * uq_tenant_endpoint docblock for the failure mode this prevents.
     */
    public function test_the_same_endpoint_can_be_subscribed_under_two_different_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $sharedEndpoint = 'https://push.example/shared-browser-device';

        $responseA = $this->actingAs($userA, 'tenant')
            ->postJson($this->panelUrl($tenantA, 'push/subscribe'), $this->subscriptionPayload($sharedEndpoint));
        $responseA->assertOk()->assertJson(['status' => 'subscribed']);

        // Tenant B subscribing the exact same endpoint must succeed too,
        // not collide with tenant A's row.
        $responseB = $this->actingAs($userB, 'tenant')
            ->postJson($this->panelUrl($tenantB, 'push/subscribe'), $this->subscriptionPayload($sharedEndpoint));
        $responseB->assertOk()->assertJson(['status' => 'subscribed']);

        $this->assertDatabaseHas('push_subscriptions', [
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'endpoint_hash' => hash('sha256', $sharedEndpoint),
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('push_subscriptions', [
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'endpoint_hash' => hash('sha256', $sharedEndpoint),
            'is_active' => 1,
        ]);

        // Two independent rows, not one reassigned row.
        $this->assertSame(
            2,
            PushSubscription::withoutGlobalScopes()->where('endpoint_hash', hash('sha256', $sharedEndpoint))->count()
        );

        // Re-subscribing tenant A's own endpoint again must still update
        // in place, not create a second row for the same tenant.
        $this->actingAs($userA, 'tenant')
            ->postJson($this->panelUrl($tenantA, 'push/subscribe'), $this->subscriptionPayload($sharedEndpoint))
            ->assertOk();

        $this->assertSame(
            1,
            PushSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantA->id)
                ->where('endpoint_hash', hash('sha256', $sharedEndpoint))
                ->count(),
            're-subscribing the same tenant+endpoint must update the existing row, not duplicate it'
        );
    }

    public function test_destroy_does_not_leak_across_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);
        $endpoint = 'https://push.example/tenant-a-device';

        $subA = PushSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh_key' => 'k', 'auth_key' => 'a',
            'is_active' => 1,
        ]);

        $this->actingAs($userB, 'tenant')
            ->postJson($this->panelUrl($tenantB, 'push/unsubscribe'), ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertTrue($subA->fresh()->is_active, 'a user in another tenant must never be able to touch this subscription');
    }
}
