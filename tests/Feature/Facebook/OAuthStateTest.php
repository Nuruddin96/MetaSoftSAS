<?php

namespace Tests\Feature\Facebook;

use App\Exceptions\FacebookOAuthException;
use App\Services\Facebook\FacebookOAuthService;

class OAuthStateTest extends FacebookFeatureTestCase
{
    public function test_state_is_created_as_64_char_cryptographically_random_hex(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new FacebookOAuthService;

        $state1 = $service->createState($tenant, $user);
        $state2 = $service->createState($tenant, $user);

        $this->assertSame(64, strlen($state1->state));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state1->state);
        $this->assertNotSame($state1->state, $state2->state, 'two state tokens must not collide');
        $this->assertSame($tenant->id, $state1->tenant_id);
        $this->assertSame($user->id, $state1->user_id);
        $this->assertNull($state1->used_at);
    }

    public function test_valid_state_is_consumed_and_returns_the_bound_tenant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new FacebookOAuthService;

        $state = $service->createState($tenant, $user);

        $consumed = $service->validateAndConsumeState($state->state, $user->id);

        $this->assertSame($tenant->id, $consumed->tenant_id);
        $this->assertNotNull($consumed->fresh()->used_at, 'state row must be marked used after a successful consume');
    }

    public function test_expired_state_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new FacebookOAuthService;

        $state = $service->createState($tenant, $user);
        $state->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $service->validateAndConsumeState($state->state, $user->id);
            $this->fail('Expected FacebookOAuthException for an expired state.');
        } catch (FacebookOAuthException $e) {
            $this->assertSame('expired_state', $e->reason);
        }
    }

    public function test_replayed_state_is_rejected_on_second_use(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new FacebookOAuthService;

        $state = $service->createState($tenant, $user);
        $service->validateAndConsumeState($state->state, $user->id);

        try {
            $service->validateAndConsumeState($state->state, $user->id);
            $this->fail('Expected FacebookOAuthException on replay.');
        } catch (FacebookOAuthException $e) {
            $this->assertSame('already_used', $e->reason);
        }
    }

    public function test_unknown_state_is_rejected(): void
    {
        $service = new FacebookOAuthService;

        try {
            $service->validateAndConsumeState('not-a-real-token-'.bin2hex(random_bytes(28)), 1);
            $this->fail('Expected FacebookOAuthException for an unknown state.');
        } catch (FacebookOAuthException $e) {
            $this->assertSame('unknown_state', $e->reason);
        }
    }

    public function test_missing_state_is_rejected(): void
    {
        $service = new FacebookOAuthService;

        try {
            $service->validateAndConsumeState(null, 1);
            $this->fail('Expected FacebookOAuthException for a missing state.');
        } catch (FacebookOAuthException $e) {
            $this->assertSame('missing_state', $e->reason);
        }
    }

    public function test_state_is_rejected_when_authenticated_user_does_not_match(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser($tenant->id);
        $userB = $this->makeUser($tenant->id);
        $service = new FacebookOAuthService;

        $state = $service->createState($tenant, $userA);

        try {
            $service->validateAndConsumeState($state->state, $userB->id);
            $this->fail('Expected FacebookOAuthException on user mismatch.');
        } catch (FacebookOAuthException $e) {
            $this->assertSame('user_mismatch', $e->reason);
        }

        // The mismatch must not have consumed the state — the rightful user
        // can still complete the flow.
        $this->assertNull($state->fresh()->used_at);
    }
}
