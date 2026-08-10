<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Pwa\PwaServiceWorkerBuilder;

/**
 * Path tenancy means every tenant's panel lives at a different URL
 * (/shop/{slug}/panel), so a single static manifest.json can't declare a
 * meaningful start_url/scope for everyone — each tenant needs their own,
 * pointing at their own panel, so "Add to Home Screen" from inside a
 * tenant's panel installs an app that actually opens back into that same
 * tenant's panel rather than the central marketing site. Served as JSON
 * from a normal authenticated route rather than a static public file for
 * exactly that reason. Icons/name/theme stay identical for every tenant —
 * this is still one MetaSoft app, not a per-tenant white-label build.
 *
 * Scope is deliberately the panel only (not the whole tenant site) — the
 * public storefront is a customer-facing catalog/checkout, not something
 * a merchant would "install" as their business app, and keeping the
 * service worker's own max-scope this narrow (see serviceWorker() below)
 * means it can never intercept a single storefront/checkout request.
 *
 * See also \App\Http\Controllers\PwaController — the central (un-
 * authenticated, non-tenant) counterpart backing the landing page's
 * "Install App" button. Both share the exact same service-worker script
 * via PwaServiceWorkerBuilder rather than each maintaining their own copy.
 */
class PwaController extends Controller
{
    public function manifest()
    {
        $tenant = app('currentTenant');
        $panelUrl = $tenant->url().'/panel';

        return response()->json([
            'name' => 'MetaSoft SaaS',
            'short_name' => 'MetaSoft',
            'description' => 'MetaSoft — e-commerce ও ব্যবসা ব্যবস্থাপনা প্যানেল',
            'start_url' => $panelUrl,
            'scope' => $panelUrl.'/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#F4F2EA',
            'theme_color' => '#128155',
            'lang' => 'bn',
            'dir' => 'ltr',
            'icons' => [
                ['src' => asset('images/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('images/icons/icon-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('images/icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * Served from panel/sw.js (not public/sw.js) specifically so the
     * browser's default max-scope for this worker is /panel/* — it can
     * physically never register for, or intercept, anything outside the
     * panel even if the registration call below ever changed. No
     * Service-Worker-Allowed header — the default max-scope (the
     * directory containing this script) is already exactly right for
     * both tenancy modes.
     */
    public function serviceWorker(PwaServiceWorkerBuilder $builder)
    {
        return $builder->response();
    }
}
