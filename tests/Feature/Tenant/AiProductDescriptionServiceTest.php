<?php

namespace Tests\Feature\Tenant;

use App\Services\AI\AiCreditService;
use App\Services\AI\AiProductDescriptionService;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiProviderResponse;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiProductDescriptionService — onboarding Step 5's
 * "ছবি দেখে বর্ণনা লিখে দাও" button. Uses a fake AiProviderInterface (same
 * pattern as AiChatServiceTest) so the vision request shape and the
 * AiCreditService gating can be asserted deterministically, with no real
 * OpenAI call.
 */
class AiProductDescriptionServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function fakeProvider(?AiProviderResponse $response, array &$callsSeen = []): AiProviderInterface
    {
        return new class($response, $callsSeen) implements AiProviderInterface
        {
            public function __construct(protected ?AiProviderResponse $response, protected array &$callsSeen) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                $this->callsSeen[] = $messages;

                return $this->response ?? AiProviderResponse::failure();
            }
        };
    }

    public function test_a_tenant_with_no_ai_credit_is_never_charged_or_called(): void
    {
        $tenant = $this->makeTenant();
        $calls = [];
        $provider = $this->fakeProvider(null, $calls);
        $service = new AiProductDescriptionService($provider, app(AiCreditService::class));

        $this->assertFalse($service->available($tenant));

        $result = $service->describe($tenant, 'https://example.test/products/1/a.jpg');

        $this->assertFalse($result['success']);
        $this->assertSame('no_credit', $result['error']);
        $this->assertSame([], $calls);
    }

    public function test_a_successful_call_returns_the_description_and_deducts_credit(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100.0);

        $calls = [];
        $provider = $this->fakeProvider(
            AiProviderResponse::success('একটি সুন্দর গোলাপি লিপস্টিক।', 50, 20, 'gpt-5-mini'),
            $calls
        );
        $service = new AiProductDescriptionService($provider, app(AiCreditService::class));

        $result = $service->describe($tenant, 'https://example.test/products/1/a.jpg', 'Lipstick');

        $this->assertTrue($result['success']);
        $this->assertSame('একটি সুন্দর গোলাপি লিপস্টিক।', $result['description']);

        // Request shape mirrors AiAgentService::userContent() — a 'user'
        // message whose content is a multimodal parts array with exactly
        // one image_url part pointing at the URL passed in.
        $userMessage = collect($calls[0])->firstWhere('role', 'user');
        $imagePart = collect($userMessage['content'])->firstWhere('type', 'image_url');
        $this->assertSame('https://example.test/products/1/a.jpg', $imagePart['image_url']['url']);

        $this->assertLessThan(100.0, (float) app(AiCreditService::class)->balance($tenant->id));
    }

    public function test_a_failed_provider_call_never_charges_credit(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100.0);
        $service = new AiProductDescriptionService($this->fakeProvider(AiProviderResponse::failure()), app(AiCreditService::class));

        $result = $service->describe($tenant, 'https://example.test/products/1/a.jpg');

        $this->assertFalse($result['success']);
        $this->assertSame('ai_failed', $result['error']);
        $this->assertEqualsWithDelta(100.0, (float) app(AiCreditService::class)->balance($tenant->id), 0.0001);
    }
}
