<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AppUpdateConfig;

/**
 * Centralized Android APK update check — public, unauthenticated (no
 * `auth:sanctum`/`bind.tenant.token`, unlike the rest of `mobile/v1`):
 * the Flutter app must be able to check this BEFORE a tenant logs in
 * (e.g. to force-update a build too old to even reach the login screen
 * safely), so this can never sit behind the same guard as `auth/me`.
 *
 * Every field returned here is deliberately safe to expose publicly —
 * version numbers, a release-notes string, and a public HTTPS APK URL
 * already reachable to anyone with the download link. Nothing
 * admin/server-internal (no filesystem path, no credentials, no other
 * tenant's data) is ever included.
 *
 * `force_update` here is the Super Admin's own manual override (e.g. a
 * critical security patch that must apply immediately, independent of
 * build numbers) — the deterministic per-install decision described in
 * the task spec ("installed build < minimum_supported_build → force
 * update") is intentionally left to the Flutter client, which already
 * knows its own installed build number and receives
 * `minimum_supported_build` here to compare it against; the server
 * cannot compute that itself since this endpoint takes no installed-build
 * parameter.
 */
class AppUpdateController extends Controller
{
    public function show()
    {
        if (! AppUpdateConfig::tablesReady()) {
            return response()->json([
                'latest_version' => null,
                'latest_build' => null,
                'minimum_supported_version' => null,
                'minimum_supported_build' => null,
                'download_url' => null,
                'force_update' => false,
                'release_notes' => null,
            ]);
        }

        $config = AppUpdateConfig::current();

        return response()->json([
            'latest_version' => $config->latest_version,
            'latest_build' => $config->latest_build,
            'minimum_supported_version' => $config->minimum_supported_version,
            'minimum_supported_build' => $config->minimum_supported_build,
            'download_url' => $config->apkUrl(),
            'force_update' => $config->force_update,
            'release_notes' => $config->release_notes,
        ]);
    }
}
