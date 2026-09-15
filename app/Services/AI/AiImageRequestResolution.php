<?php

namespace App\Services\AI;

use App\Models\TenantProductImage;

/**
 * What App\Services\AI\AiProductImageMemoryService::resolve() decided
 * about one customer turn, for the calling job (ProcessAiAgentMessage/
 * ProcessWhatsAppAiAgentMessage) to act on — mirrors WhatsAppSendResult's
 * "small typed outcome, not a raw array" convention.
 *
 * - none: no image request detected, or one was detected but nothing in
 *   the tenant's saved product images plausibly matches — the caller
 *   changes nothing and the normal AiAgentService flow proceeds exactly
 *   as if this service didn't exist.
 * - clarify: an image was asked for and two or more saved images are
 *   comparably plausible — the caller sends ONE short clarifying
 *   question (no OpenAI call, no image) instead of guessing.
 * - sendAndStop: a confident, unambiguous match, and the customer's
 *   message contains nothing else worth answering — the caller sends
 *   the image with a short canned caption and skips OpenAI entirely for
 *   this turn (see AiProductImageMemoryService's docblock for why this
 *   never needs an AI call).
 * - sendAndContinue: a confident, unambiguous match, but the message
 *   also asks something else (e.g. "দাম কত আর ছবি দেন") — the caller
 *   sends the image as a plain attachment (no caption — the upcoming
 *   text reply will address the rest) and still calls AiAgentService
 *   normally, so the customer's other question gets a real answer. This
 *   keeps the turn to "one text reply plus necessary media" (never two
 *   text replies) rather than skipping the customer's actual question.
 */
final class AiImageRequestResolution
{
    private function __construct(
        public readonly string $action,
        public readonly ?TenantProductImage $image,
    ) {}

    public static function none(): self
    {
        return new self('none', null);
    }

    public static function clarify(): self
    {
        return new self('clarify', null);
    }

    public static function sendAndStop(TenantProductImage $image): self
    {
        return new self('send_and_stop', $image);
    }

    public static function sendAndContinue(TenantProductImage $image): self
    {
        return new self('send_and_continue', $image);
    }

    public function isNone(): bool
    {
        return $this->action === 'none';
    }

    public function isClarify(): bool
    {
        return $this->action === 'clarify';
    }

    public function isSendAndStop(): bool
    {
        return $this->action === 'send_and_stop';
    }

    public function isSendAndContinue(): bool
    {
        return $this->action === 'send_and_continue';
    }
}
