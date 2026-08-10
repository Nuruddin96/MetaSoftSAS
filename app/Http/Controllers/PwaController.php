<?php

namespace App\Http\Controllers;

use App\Services\Pwa\PwaServiceWorkerBuilder;

/**
 * Backs the "Install App" button on the central landing page
 * (resources/views/central/landing.blade.php). A distinct manifest from
 * Tenant\PwaController is unavoidable — a manifest/service-worker pair is
 * inherently tied to the scope it's registered from, and the central
 * domain (unauthenticated, no tenant context) is a different origin scope
 * than any tenant's own /shop/{slug}/panel — but this is NOT a second PWA
 * mechanism: same icon set, same theme, and the identical service-worker
 * script (see PwaServiceWorkerBuilder, shared with Tenant\PwaController
 * rather than duplicated). A visitor installing from here gets the
 * MetaSoft marketing/login shell; a logged-in tenant's own panel remains
 * the separate, more useful day-to-day install from inside /panel itself.
 *
 * Both this controller's service worker and the tenant one register at
 * their own directory's default scope (/ here, /panel/ there) — Chrome
 * correctly resolves overlapping scopes to whichever is more specific for
 * a given request, so a visitor who has both installed never sees a
 * conflict.
 */
class PwaController extends Controller
{
    public function manifest()
    {
        $root = 'https://'.config('app.central_domain').'/';

        return response()->json([
            'name' => 'MetaSoft SaaS',
            'short_name' => 'MetaSoft',
            'description' => 'MetaSoft — বাংলাদেশের ব্যবসার জন্য ই-কমার্স ও বিজনেস অটোমেশন প্ল্যাটফর্ম',
            'start_url' => $root,
            'scope' => $root,
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

    public function serviceWorker(PwaServiceWorkerBuilder $builder)
    {
        return $builder->response();
    }
}
