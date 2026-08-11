<?php

namespace App\Exceptions;

/**
 * Thrown by WhatsAppOAuthService::validateAndConsumeState() for every
 * rejection path (missing/unknown/expired/replayed state, user mismatch).
 * `reason` is a stable machine-readable slug for logging — callers should
 * log $reason, never the raw state token. Mirrors FacebookOAuthException's
 * shape exactly (the state-validation concept genuinely matches Facebook's
 * OAuth state design, per this phase's instruction to reuse it) — kept as
 * its own class rather than shared, same "two call sites is not yet a
 * pattern" reasoning used elsewhere in this WhatsApp integration.
 */
class WhatsAppOAuthException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message ?: $reason);
    }
}
