<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppMessage;

/**
 * The abstraction Phase 4/UI is meant to consume — sendText()/sendMedia()
 * return this, not a raw Graph API response shape, so a controller never
 * has to know whether the underlying call succeeded via HTTP 200 or failed
 * via a 4xx/5xx/embedded-error/connection-exception; it only ever branches
 * on ->successful.
 */
final class WhatsAppSendResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?WhatsAppMessage $message,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {}

    public static function success(WhatsAppMessage $message): self
    {
        return new self(true, $message, null, null);
    }

    /**
     * $message is the persisted failed-attempt row when one was created
     * (a genuine Meta API rejection — see WhatsAppSendService::send()) or
     * null for a pre-flight failure where no send was ever attempted (e.g.
     * no WhatsApp number connected for this tenant at all).
     */
    public static function failure(?string $errorCode, ?string $errorMessage, ?WhatsAppMessage $message = null): self
    {
        return new self(false, $message, $errorCode, $errorMessage);
    }
}
