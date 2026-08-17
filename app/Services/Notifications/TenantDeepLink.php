<?php

namespace App\Services\Notifications;

use App\Models\Tenant;

/**
 * Builds an absolute, tenant-correct panel URL from outside an HTTP
 * request. A queued listener has no current request to inherit a host or
 * URL::defaults() from — see bootstrap/app.php's redirectGuestsTo docblock
 * for the same class of problem solved for a different call site — so
 * plain route() can't be trusted here as-is:
 *
 *  - path mode needs an explicit tenant_slug route parameter (route() has
 *    no request to read it from).
 *  - subdomain mode needs the tenant's own subdomain host prepended, since
 *    route()'s default host is config('app.url') — the CENTRAL domain, not
 *    this tenant's — outside a request that already resolved one.
 */
class TenantDeepLink
{
    public function build(Tenant $tenant, string $routeName, array $params = []): string
    {
        if (config('app.tenancy_mode', 'subdomain') === 'path') {
            return route($routeName, ['tenant_slug' => $tenant->subdomain] + $params);
        }

        $path = route($routeName, $params, false);

        return 'https://'.$tenant->subdomain.'.'.config('app.central_domain').$path;
    }
}
