<?php

namespace App\Exceptions;

/**
 * Thrown by WhatsAppOAuthService for any failed Meta Graph API call during
 * the connection flow. Carries Meta's own error payload (message/type/code)
 * — never a token — so callers can log it directly and decide whether it
 * specifically means "the token is invalid/revoked" via isInvalidToken().
 * Mirrors FacebookGraphException's shape exactly.
 */
class WhatsAppGraphException extends \RuntimeException
{
    public function __construct(protected array $error, string $message = '')
    {
        parent::__construct($message ?: ($error['message'] ?? 'WhatsApp Cloud API error'));
    }

    /** Meta's standard "invalid/expired OAuth token" error code. */
    public function isInvalidToken(): bool
    {
        return (int) ($this->error['code'] ?? 0) === 190;
    }

    public function errorPayload(): array
    {
        return $this->error;
    }
}
