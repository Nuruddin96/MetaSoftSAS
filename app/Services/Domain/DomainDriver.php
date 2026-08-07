<?php

namespace App\Services\Domain;

use App\Models\Tenant;

/**
 * One implementation per hosting environment (shared hosting today; Nginx/Caddy-on-VPS
 * later). DomainManager::driver() resolves whichever one is configured — the DB schema
 * and every controller/view built against this interface stay identical regardless of
 * which driver is active.
 */
interface DomainDriver
{
    /** Look up the tenant's requested domain's DNS and confirm the verification TXT record is published. */
    public function verifyDns(Tenant $tenant): bool;

    /** Plain-language checklist of what staff/ops need to do to make this domain live under this driver. */
    public function activationInstructions(Tenant $tenant): string;
}
