<?php

namespace App\Services\Domain;

class DomainManager
{
    /** Currently configured driver — 'manual' on shared hosting today (see config/domains.php). */
    public static function driver(): DomainDriver
    {
        return app(DomainDriver::class);
    }

    /** Random token a tenant is asked to publish in a DNS TXT record to prove domain ownership. */
    public static function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The exact TXT record value every driver checks for — kept in one place so drivers never disagree on the format. */
    public static function expectedTxtValue(string $token): string
    {
        return 'metasoft-domain-verify='.$token;
    }
}
