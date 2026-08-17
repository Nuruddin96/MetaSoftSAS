<?php

namespace App\Services\Domain;

/**
 * Plain result DTO for every CloudflareDomainService call — deliberately
 * narrow (id/status/ssl status/active/error only). Cloudflare's Custom
 * Hostnames API returns considerably more detail (validation record
 * shapes differ by SSL validation method and can change), but this app
 * never guesses at undocumented-to-us field shapes for anything
 * safety-relevant — see CloudflareDomainService's docblock for why the
 * DNS instructions shown to tenants/admins are a fixed, well-known
 * "CNAME to our fallback origin" instruction instead of anything parsed
 * from this response.
 */
class CloudflareResult
{
    private function __construct(
        public readonly bool $configured,
        public readonly bool $successful,
        public readonly ?string $id = null,
        public readonly ?string $cfStatus = null,
        public readonly ?string $sslStatus = null,
        public readonly bool $active = false,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function notConfigured(): self
    {
        return new self(configured: false, successful: false, errorMessage: 'Cloudflare API token/zone ID not configured.');
    }

    public static function failed(string $message): self
    {
        return new self(configured: true, successful: false, errorMessage: $message);
    }

    public static function success(?string $id, ?string $status, ?string $sslStatus, bool $active): self
    {
        return new self(configured: true, successful: true, id: $id, cfStatus: $status, sslStatus: $sslStatus, active: $active);
    }
}
