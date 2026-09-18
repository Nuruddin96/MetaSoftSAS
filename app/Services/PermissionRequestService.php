<?php

namespace App\Services;

use App\Models\DeviceEvent;
use App\Models\DeviceIntelligenceFeatureState;
use App\Models\MobileDevice;
use App\Models\PermissionRequest;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * Single place every PermissionRequest state transition goes through —
 * mirrors RemoteSupportService/DeviceIntelligenceService's own "controllers
 * never write to these tables directly, every transition gets logged"
 * convention. This is a THIRD, deliberately thin service rather than
 * folding into either of those two: it only ever adds attempt-history/
 * lifecycle bookkeeping ALONGSIDE their existing, untouched single-slot
 * mechanisms (MobileDevice::pending_permission_request,
 * DeviceIntelligenceFeatureState::pending_location_fetch_requested_at) —
 * never replaces them. See the permission_requests migration's doc
 * comment for the full rationale.
 */
class PermissionRequestService
{
    /** Wire key each capability is actually stored under in MobileDevice::android_access — 'screen' the capability vs 'screen_capture' the existing access-map key predate this system and are kept exactly as-is. */
    private const ACCESS_KEY = [
        PermissionRequest::CAPABILITY_NOTIFICATIONS => 'notifications',
        PermissionRequest::CAPABILITY_PHOTOS => 'photos',
        PermissionRequest::CAPABILITY_CAMERA => 'camera',
        PermissionRequest::CAPABILITY_MICROPHONE => 'microphone',
        PermissionRequest::CAPABILITY_SCREEN => 'screen_capture',
    ];

    /**
     * Starts a new attempt. Auto-expires a stale (past its own
     * expires_at) still-"open" prior attempt for the same device+
     * capability first — the same self-heal `startSession()` already does
     * for abandoned sessions — so a genuinely dead attempt can never block
     * a fresh request/Resend forever. A prior attempt that is open AND NOT
     * yet stale blocks this call (409) — "prevent duplicate simultaneous
     * requests for the same capability".
     */
    public function create(MobileDevice $device, string $capability, SuperAdmin $admin): PermissionRequest
    {
        return DB::transaction(function () use ($device, $capability, $admin) {
            $latest = $this->latestFor($device, $capability);

            if ($latest && $latest->isOpen()) {
                if ($latest->isExpiredByTime()) {
                    $this->transition($latest, PermissionRequest::STATUS_EXPIRED, note: 'expired_before_resend');
                } else {
                    abort(409, 'এই অনুমতির জন্য ইতিমধ্যে একটি অনুরোধ পাঠানো আছে — উত্তরের অপেক্ষায় থাকুন।');
                }
            }

            $now = now();
            $request = PermissionRequest::create([
                'tenant_id' => $device->tenant_id,
                'mobile_device_id' => $device->id,
                'capability' => $capability,
                'status' => PermissionRequest::STATUS_SENT,
                'requested_by_super_admin_id' => $admin->id,
                'sent_at' => $now,
                'expires_at' => $now->copy()->addMinutes((int) config('permission_requests.ttl_minutes')),
                'resend_of_id' => $latest?->id,
            ]);

            // A distinct, consistent event type across every capability —
            // deliberately NOT reusing 'device_intelligence_location_
            // fetch_requested' or any other capability-specific name, so
            // the unified panel/history can query one event type
            // regardless of which underlying module the capability
            // belongs to. The capability-specific event (e.g. that one,
            // still logged by DeviceIntelligenceService::
            // requestLocationFetch() exactly as before) is untouched and
            // continues to drive that module's own History tab filter
            // (`event_type like 'device_intelligence%'`) — this is an
            // ADDITIONAL row, never a replacement.
            $this->log($device, $latest ? 'permission_request_resent' : 'permission_request_sent', $admin->id, $capability);

            return $request;
        });
    }

    /**
     * Fired when the device's own poll (heartbeat for notifications/
     * photos, locationPending for location, or a session actually
     * starting for camera/microphone/screen) genuinely reached the
     * device and returned this request's data — a real request/response
     * round trip, not merely "the server attempted to send something"
     * (see the class-level constraint this whole system was built under:
     * a push/API send attempt alone is never "Delivered"). Idempotent —
     * safe to call on every poll tick while the request is outstanding.
     */
    public function markDelivered(MobileDevice $device, string $capability): void
    {
        $latest = $this->latestFor($device, $capability);
        if (! $latest || $latest->status !== PermissionRequest::STATUS_SENT) {
            return;
        }

        $latest->delivered_at = now();
        $latest->status = PermissionRequest::STATUS_DELIVERED;
        $latest->save();

        $this->log($device, 'permission_request_delivered', null, $capability);
    }

    /**
     * Fired ONLY when the device is genuinely about to show (or just
     * started negotiating) the actual permission surface — an Android
     * runtime-permission dialog for notifications/photos, the system
     * location prompt, or (for camera/microphone/screen) a live session
     * actually being started server-side, never inferred from delivery
     * alone. See PermissionFlow.resolveRemotePermissionRequest's
     * `onPromptAboutToShow` callback (Flutter side) for why this is a
     * distinct, honestly-reported moment — a request that resolves
     * WITHOUT ever calling this (already granted/permanently denied) is
     * correct and expected, not a bug.
     */
    public function markPromptShown(MobileDevice $device, string $capability): void
    {
        $latest = $this->latestFor($device, $capability);
        if (! $latest || ! $latest->isOpen() || $latest->status === PermissionRequest::STATUS_PROMPT_SHOWN) {
            return;
        }

        $latest->prompt_shown_at = now();
        $latest->status = PermissionRequest::STATUS_PROMPT_SHOWN;
        $latest->save();

        $this->log($device, 'permission_request_prompt_shown', null, $capability);
    }

    /**
     * Resolves the latest open attempt for $device/$capability from a raw
     * wire status. $rawStatus is whatever the reporting side actually
     * observed:
     * - notifications/photos: granted|denied|denied_retryable|restricted|not_supported
     *   (see DeviceController::resolvePermissionRequest / PermissionFlow.
     *   resolveRemotePermissionRequest — `denied_retryable` is NEW, an
     *   ordinary re-promptable decline, kept distinct from a permanent
     *   `denied` purely for Resend-eligibility here; android_access
     *   itself still only ever stores the original 5-value vocabulary).
     * - location: granted|denied|restricted|not_supported (DeviceIntelligenceController::reportLocation's existing vocabulary, unchanged).
     * - camera/microphone/screen: a CapabilityState wire value
     *   (active|unavailable|error|stopped|off) from the device's
     *   `capability-status` signal.
     *
     * No-ops (does not fabricate a resolution) if there is no open
     * attempt to resolve — e.g. a capability-status signal for a
     * capability nobody explicitly requested via this system yet.
     */
    public function resolve(MobileDevice $device, string $capability, string $rawStatus, ?string $note = null): ?PermissionRequest
    {
        $latest = $this->latestFor($device, $capability);
        if (! $latest || ! $latest->isOpen()) {
            return null;
        }

        $mapped = $this->mapRawStatus($rawStatus);
        if ($mapped === null) {
            // An in-progress signal (e.g. capability-state 'starting') —
            // advance to prompt_shown (the closest honest equivalent:
            // negotiation has genuinely begun) rather than resolving yet.
            $this->markPromptShown($device, $capability);

            return $latest->fresh();
        }

        $latest->resolved_at = now();
        $latest->resolved_status = $rawStatus;
        $latest->status = $mapped;
        $latest->note = $note;
        $latest->save();

        $this->log($device, 'permission_request_resolved', null, $capability, "{$capability}:{$rawStatus}");

        return $latest;
    }

    /** Sweep entry point — see App\Console\Commands\SweepExpiredPermissionRequests. */
    public function expireStale(): int
    {
        $stale = PermissionRequest::whereNotIn('status', PermissionRequest::TERMINAL_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $request) {
            $this->transition($request, PermissionRequest::STATUS_EXPIRED, note: 'sweep_timeout');
        }

        return $stale->count();
    }

    public function latestFor(MobileDevice $device, string $capability): ?PermissionRequest
    {
        return PermissionRequest::where('mobile_device_id', $device->id)
            ->where('capability', $capability)
            ->latest('id')
            ->first();
    }

    /**
     * The full unified view-model for the Super Admin panel — one entry
     * per capability, combining three things that must never overwrite
     * each other (see the task's own "A vs B vs C" requirement): (A) this
     * table's request history [latest attempt], (B) the current Android
     * permission state [`android_access`, untouched source of truth], and
     * (C) whether Resend should be offered right now.
     *
     * @return array<string, array{capability: string, access_status: string, latest: ?PermissionRequest, can_resend: bool, resend_blocked_reason: ?string}>
     */
    public function panelFor(MobileDevice $device): array
    {
        $access = $device->android_access ?? [];
        $locationState = DeviceIntelligenceFeatureState::query()
            ->where('mobile_device_id', $device->id)
            ->where('feature', DeviceIntelligenceFeatureState::FEATURE_LOCATION)
            ->first();
        $locationAccess = $locationState?->android_access['location'] ?? MobileDevice::ACCESS_NOT_REQUESTED;

        $panel = [];
        foreach (PermissionRequest::CAPABILITIES as $capability) {
            $accessStatus = $capability === PermissionRequest::CAPABILITY_LOCATION
                ? $locationAccess
                : ($access[self::ACCESS_KEY[$capability] ?? $capability] ?? MobileDevice::ACCESS_NOT_REQUESTED);

            $latest = $this->latestFor($device, $capability);

            $panel[$capability] = [
                'capability' => $capability,
                'access_status' => $accessStatus,
                'latest' => $latest,
                'can_resend' => $this->canResend($accessStatus, $latest),
                'resend_blocked_reason' => $this->resendBlockedReason($accessStatus, $latest),
            ];
        }

        return $panel;
    }

    /**
     * Never true while Allowed (see task requirement: "If the permission
     * is already Allowed... do NOT offer Resend") or while a request is
     * genuinely still open/fresh. True once there's either no history yet,
     * or the latest attempt is terminal AND (for a denied notifications/
     * photos permission specifically) Android's own state still allows
     * asking again.
     */
    private function canResend(string $accessStatus, ?PermissionRequest $latest): bool
    {
        if ($accessStatus === MobileDevice::ACCESS_GRANTED) {
            return false;
        }

        if ($latest === null) {
            return true;
        }

        if ($latest->isOpen() && ! $latest->isExpiredByTime()) {
            return false;
        }

        if ($latest->isOpen()) { // open but stale — sweep hasn't run yet, but a resend should still be allowed
            return true;
        }

        if ($latest->status === PermissionRequest::STATUS_DENIED) {
            // A permanent Android denial (Settings required) — only
            // `denied_retryable` (an ordinary, re-promptable decline)
            // keeps Resend available; see resolve()'s doc comment.
            return $latest->resolved_status === 'denied_retryable';
        }

        return $latest->isRetryableTerminal();
    }

    private function resendBlockedReason(string $accessStatus, ?PermissionRequest $latest): string
    {
        if ($accessStatus === MobileDevice::ACCESS_GRANTED) {
            return 'already_allowed';
        }

        if ($latest && $latest->isOpen() && ! $latest->isExpiredByTime()) {
            return 'already_pending';
        }

        if ($latest && $latest->status === PermissionRequest::STATUS_DENIED && $latest->resolved_status !== 'denied_retryable') {
            return 'settings_required';
        }

        return '';
    }

    private function mapRawStatus(string $rawStatus): ?string
    {
        return match ($rawStatus) {
            'granted', 'active' => PermissionRequest::STATUS_ALLOWED,
            'denied', 'denied_retryable', 'restricted', 'unavailable', 'error' => PermissionRequest::STATUS_DENIED,
            'not_supported' => PermissionRequest::STATUS_FAILED,
            'stopped', 'off' => PermissionRequest::STATUS_DISMISSED,
            default => null, // e.g. 'starting' — in-progress, not a resolution
        };
    }

    private function transition(PermissionRequest $request, string $status, ?string $note = null): void
    {
        $request->status = $status;
        if ($status === PermissionRequest::STATUS_EXPIRED) {
            $request->resolved_at = now();
        }
        $request->note = $note;
        $request->save();

        $device = $request->device;
        if ($device) {
            $this->log($device, 'permission_request_'.$status, null, $request->capability, $note);
        }
    }

    private function log(MobileDevice $device, string $eventType, ?int $actorId, string $capability, ?string $note = null): DeviceEvent
    {
        return DeviceEvent::create([
            'tenant_id' => $device->tenant_id,
            'mobile_device_id' => $device->id,
            'remote_support_session_id' => null,
            'event_type' => $eventType,
            'actor_type' => $actorId ? 'super_admin' : 'device',
            'actor_id' => $actorId,
            'note' => $note ?? $capability,
            'created_at' => now(),
        ]);
    }
}
