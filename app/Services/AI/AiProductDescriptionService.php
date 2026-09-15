<?php

namespace App\Services\AI;

use App\Models\Tenant;
use App\Services\AI\Providers\AiProviderInterface;

/**
 * "ছবি দেখে বর্ণনা লিখে দাও" — onboarding Step 5's optional AI product
 * description generator. Deliberately thin: reuses the SAME
 * AiProviderInterface/OpenAiProvider and AiCreditService wallet every other
 * AI feature in this codebase already goes through (AiAgentService,
 * AiChatService), rather than a new provider or a separate credit system.
 *
 * The vision request shape (a 'user' message with a text part + an
 * image_url part) mirrors AiAgentService::userContent() exactly — that
 * method is the proof this provider already supports OpenAI multimodal
 * image understanding in production (Messenger customer photos), so no new
 * AI infrastructure is needed here.
 *
 * Only ever called when the tenant explicitly clicks the AI button
 * (OnboardingController::describeImage) — never automatically on upload.
 */
class AiProductDescriptionService
{
    public function __construct(
        protected AiProviderInterface $provider,
        protected AiCreditService $credits,
    ) {}

    /** Same "can this tenant make an AI call right now" gate every other AI feature checks before spending — see AiCreditService::hasCredit()'s docblock. */
    public function available(Tenant $tenant): bool
    {
        return $this->credits->hasCredit($tenant->id);
    }

    /**
     * @return array{success: bool, description: ?string, error: ?string}
     *                                                                    error is one of 'no_credit'|'ai_failed', suitable for a
     *                                                                    friendly disabled/error state — never surfaced to the
     *                                                                    tenant as raw text.
     */
    public function describe(Tenant $tenant, string $imageUrl, ?string $productName = null): array
    {
        if (! $this->available($tenant)) {
            return ['success' => false, 'description' => null, 'error' => 'no_credit'];
        }

        $userParts = array_values(array_filter([
            $productName ? ['type' => 'text', 'text' => "Product name (may be empty/unhelpful): {$productName}"] : null,
            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
        ]));

        $response = $this->provider->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $userParts],
        ]);

        if (! $response->successful || ! $response->reply) {
            return ['success' => false, 'description' => null, 'error' => 'ai_failed'];
        }

        $this->credits->recordUsage(
            $tenant->id,
            $response->inputTokens,
            $response->outputTokens,
            $response->model ?? (string) config('ai.openai_model'),
            'onboarding_product_description',
        );

        return ['success' => true, 'description' => trim($response->reply), 'error' => null];
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are helping a small Bangladeshi shop owner write a product listing description from a photo of the product they're about to sell online.

            Write a short, natural product description in Bengali (2-4 sentences) that a real shop owner might write — plain, sales-friendly, not overly formal.

            Base it ONLY on what is actually visible in the image — color, apparent material/type, general style. Never invent or guess a price, brand name, exact size, exact material composition, or any specification you cannot see. If the image is unclear or doesn't look like a sellable product, say so briefly in Bengali instead of guessing.

            Reply with the description text only — no headings, no quotation marks, no "এখানে বর্ণনা:" preface.
            PROMPT;
    }
}
