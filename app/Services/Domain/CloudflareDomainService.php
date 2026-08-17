<?php

namespace App\Services\Domain;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare "Custom Hostnames for SaaS" integration — automates DNS
 * validation tracking + SSL certificate issuance for a tenant's own
 * external domain, entirely at Cloudflare's edge, PROVIDED:
 *
 *   1. CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID are set (config/
 *      services.php, never hardcoded) — see isConfigured().
 *   2. The "Cloudflare for SaaS" (Custom Hostnames) product is actually
 *      enabled on that zone in the Cloudflare dashboard — a one-time,
 *      account-level toggle this API cannot itself turn on. If it isn't
 *      enabled, Cloudflare's API call itself fails with a clear error,
 *      which this service surfaces as CloudflareResult::failed() rather
 *      than ever pretending success.
 *
 * When either prerequisite is missing, every method here degrades to
 * CloudflareResult::notConfigured()/failed() — SuperAdmin\TenantController
 * shows the tenant/admin the SAME manual "point your domain here"
 * instructions the pre-existing ManualProvisionDriver already used,
 * never a false "connected" state. This is the explicit "fail gracefully,
 * show a clear Cloudflare configuration required state, never corrupt
 * the domain mapping" requirement.
 *
 * IMPORTANT — what this does NOT and CANNOT solve on this specific
 * hosting environment (confirmed by direct testing, not assumption): the
 * origin web server (Hostinger shared hosting, no WHM/root access) 403s
 * any request whose Host header doesn't match a registered vhost/addon
 * domain, REGARDLESS of Cloudflare. Cloudflare automates DNS+SSL at its
 * edge; it does not and cannot register a new vhost on this origin. See
 * SuperAdmin\TenantController::connectDomain()'s self-verification check
 * (a real HTTP request to the tenant's domain) for how this app proves
 * end-to-end connectivity before ever marking a domain fully Active,
 * instead of trusting Cloudflare's edge status alone.
 */
class CloudflareDomainService
{
    public function isConfigured(): bool
    {
        return filled(config('services.cloudflare.token')) && filled(config('services.cloudflare.zone_id'));
    }

    /** Creates a Custom Hostname resource for $hostname in our zone — the start of the "Connect" action. */
    public function createCustomHostname(string $hostname): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        return $this->request('post', 'custom_hostnames', [
            'hostname' => $hostname,
            'ssl' => ['method' => 'cname', 'type' => 'dv'],
        ]);
    }

    /** Re-checks Cloudflare's current status for a previously-created custom hostname — the "Refresh status" action. */
    public function getCustomHostnameStatus(string $cloudflareId): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        return $this->request('get', "custom_hostnames/{$cloudflareId}");
    }

    /** Removes the Custom Hostname resource — called when an admin deletes/deactivates a domain mapping. Best-effort: logs and swallows failures, never blocks the local deactivate/delete action. */
    public function deleteCustomHostname(string $cloudflareId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $result = $this->request('delete', "custom_hostnames/{$cloudflareId}");

        if (! $result->successful) {
            Log::warning('CloudflareDomainService: failed to delete custom hostname (non-fatal).', [
                'cloudflare_id' => $cloudflareId,
                'error' => $result->errorMessage,
            ]);
        }
    }

    /**
     * The fixed CNAME target every tenant domain (or its www/root,
     * whichever their registrar supports) must point to — same
     * instruction shown whether Cloudflare is configured or not, since
     * it's true either way: a domain outside our Cloudflare zone can
     * only ever reach us via a real DNS record pointing here. Defaults
     * to the central domain (already Cloudflare-proxied — see this
     * class's docblock) so the instruction is correct out of the box.
     */
    public function fallbackOriginTarget(): string
    {
        return config('services.cloudflare.fallback_origin') ?: config('app.central_domain', 'metasoftbd.com');
    }

    protected function request(string $method, string $path, array $body = []): CloudflareResult
    {
        try {
            $response = Http::withToken(config('services.cloudflare.token'))
                ->timeout(15)
                ->{$method}($this->endpoint($path), $body);
        } catch (\Throwable $e) {
            Log::warning('CloudflareDomainService: API request failed.', [
                'path' => $path,
                'exception' => get_class($e),
            ]);

            return CloudflareResult::failed('Cloudflare API unreachable: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $message = $body['errors'][0]['message'] ?? ('Cloudflare API returned HTTP '.$response->status());

            return CloudflareResult::failed($message);
        }

        $result = $body['result'] ?? [];
        $status = $result['status'] ?? null;
        $sslStatus = $result['ssl']['status'] ?? null;

        return CloudflareResult::success(
            id: $result['id'] ?? null,
            status: $status,
            sslStatus: $sslStatus,
            active: $status === 'active' && $sslStatus === 'active',
        );
    }

    protected function endpoint(string $path): string
    {
        $zone = config('services.cloudflare.zone_id');

        return "https://api.cloudflare.com/client/v4/zones/{$zone}/{$path}";
    }
}
