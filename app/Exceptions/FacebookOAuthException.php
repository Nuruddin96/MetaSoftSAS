<?php

namespace App\Exceptions;

/**
 * Thrown by FacebookOAuthService::validateAndConsumeState() for every
 * rejection path (missing/unknown/expired/replayed state, user mismatch).
 * `reason` is a stable machine-readable slug for logging — callers should
 * log $reason, never the raw state token.
 */
class FacebookOAuthException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message ?: $reason);
    }
}
