<?php

namespace App\Exceptions;

/**
 * Thrown by FacebookOAuthService for any failed Meta Graph API call. Carries
 * Meta's own error payload (message/type/code/fbtrace_id) — never a token —
 * so callers can log it directly and decide whether it specifically means
 * "the token is invalid/revoked" via isInvalidToken().
 */
class FacebookGraphException extends \RuntimeException
{
    public function __construct(protected array $error, string $message = '')
    {
        parent::__construct($message ?: ($error['message'] ?? 'Facebook Graph API error'));
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
