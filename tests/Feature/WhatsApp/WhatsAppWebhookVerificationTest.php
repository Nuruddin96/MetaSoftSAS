<?php

namespace Tests\Feature\WhatsApp;

class WhatsAppWebhookVerificationTest extends WhatsAppFeatureTestCase
{
    public function test_valid_verification_challenge_is_echoed_back(): void
    {
        config(['whatsapp.verify_token' => 'test-verify-token']);

        $response = $this->get('/webhook/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'challenge-123',
        ]));

        $response->assertOk();
        $response->assertSee('challenge-123');
    }

    public function test_wrong_verify_token_is_rejected(): void
    {
        config(['whatsapp.verify_token' => 'test-verify-token']);

        $response = $this->get('/webhook/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong-token',
            'hub_challenge' => 'challenge-123',
        ]));

        $response->assertStatus(403);
    }

    public function test_missing_hub_mode_is_rejected(): void
    {
        config(['whatsapp.verify_token' => 'test-verify-token']);

        $response = $this->get('/webhook/whatsapp?'.http_build_query([
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'challenge-123',
        ]));

        $response->assertStatus(403);
    }
}
