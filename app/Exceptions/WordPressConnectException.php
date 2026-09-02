<?php

namespace App\Exceptions;

/**
 * Thrown by WordPressConnectorService::validateAndConsumeToken() for every
 * rejection path (missing/unknown/expired/replayed/mismatched token).
 * `reason` is a stable machine-readable slug for logging — callers should
 * log $reason, never the raw connection token.
 */
class WordPressConnectException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message ?: $reason);
    }
}
