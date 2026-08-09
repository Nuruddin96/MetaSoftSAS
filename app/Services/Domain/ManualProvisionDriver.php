<?php

namespace App\Services\Domain;

use App\Models\Tenant;

/**
 * Production driver for Hostinger shared hosting. No server/API automation is available
 * here — DNS verification is the only part this driver can do itself; everything past
 * that (adding the domain in cPanel, SSL) is a manual step staff perform outside the app.
 */
class ManualProvisionDriver implements DomainDriver
{
    public function verifyDns(Tenant $tenant): bool
    {
        if (! $tenant->custom_domain_requested || ! $tenant->custom_domain_verification_token) {
            return false;
        }

        $expected = DomainManager::expectedTxtValue($tenant->custom_domain_verification_token);
        $records = @dns_get_record($tenant->custom_domain_requested, DNS_TXT) ?: [];

        foreach ($records as $record) {
            if (($record['txt'] ?? null) === $expected) {
                return true;
            }
        }

        return false;
    }

    public function activationInstructions(Tenant $tenant): string
    {
        return "1. Add {$tenant->custom_domain_requested} as an Addon/Parked Domain in cPanel, pointing at the same document root as the main app.\n"
             ."2. Confirm HTTPS is issued (Hostinger AutoSSL, if enabled, usually picks this up automatically once the addon domain is added).\n"
             .'3. Once the domain loads the app, click Activate to start serving this tenant on it.';
    }
}
