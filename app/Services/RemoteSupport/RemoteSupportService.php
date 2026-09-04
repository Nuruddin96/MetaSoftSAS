<?php

namespace App\Services\RemoteSupport;

use App\Models\DeviceEvent;
use App\Models\MobileDevice;
use App\Models\RemoteSupportSession;
use App\Models\RemoteSupportSetting;
use App\Models\RemoteSupportSignal;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single place every Remote Support state mutation goes through — tenant
 * enable/disable, device registration (auto-approved, see registerDevice's
 * doc comment) and revocation, heartbeats, and session lifecycle — so
 * every transition is validated and logged as a DeviceEvent
 * (docs/device-lifecycle.md "Session audit trail") in the same transaction
 * as the change it describes. Controllers never write to these tables
 * directly.
 *
 * Trust model is two-tier now (simplified from an earlier design that
 * required a per-device Super Admin approval code, removed entirely —
 * see registerDevice's doc comment for why that's still safe): (1)
 * tenant-level Super Admin enable (RemoteSupportSetting) and (2)
 * per-session MediaProjection consent, which is inherently on-device and
 * can never be represented by a server-side flag — see startSession's
 * doc comment.
 */
class RemoteSupportService
{
    public function setTenantEnabled(Tenant $tenant, bool $enabled, SuperAdmin $admin): RemoteSupportSetting
    {
        return DB::transaction(function () use ($tenant, $enabled, $admin) {
            $setting = RemoteSupportSetting::firstOrNew(['tenant_id' => $tenant->id]);

            $setting->enabled = $enabled;
            if ($enabled) {
                $setting->enabled_by_super_admin_id = $admin->id;
                $setting->enabled_at = now();
            } else {
                $setting->disabled_by_super_admin_id = $admin->id;
                $setting->disabled_at = now();
            }
            $setting->save();

            $this->log(
                tenantId: $tenant->id,
                eventType: $enabled ? 'remote_support_enabled' : 'remote_support_disabled',
                actorType: 'super_admin',
                actorId: $admin->id,
            );

            return $setting;
        });
    }

    /**
     * Called from the mobile API by an authenticated tenant user's own
     * login token (not the device credential — that doesn't exist yet at
     * this point), and ONLY once the on-device "Allow Access" permission
     * flow has actually completed (see Api\Mobile\DeviceController::
     * register's doc comment) — never before.
     *
     * Auto-approves immediately (no verification code, no per-device
     * Super Admin confirmation step) — this is a deliberate trust-model
     * simplification: DeviceController::register() has ALREADY verified
     * `tenant->hasRemoteSupportEnabled()` before this is ever called, so
     * the only two ways to reach this method at all are (a) a
     * legitimately authenticated staff login at a tenant a Super Admin
     * has explicitly opted in, or (b) that login token being compromised
     * — a risk identical to every other authenticated mobile endpoint in
     * this codebase, not something a per-device code was ever actually
     * defending against once tenant-level enable already gates it. The
     * security boundary that matters (which tenants can use Remote
     * Support at all) still requires an explicit Super Admin action; see
     * docs/security-model.md's trust tiers, now two rather than three.
     *
     * A REVOKED device_uuid is the one case that must NOT auto-approve —
     * that action is a deliberate Super Admin security decision and must
     * stay terminal regardless of who re-registers it (previously this
     * only blocked a *different* user; now it blocks unconditionally,
     * since a bypassable revoke would be exactly the kind of security
     * weakening removing the code flow must not introduce).
     */
    public function registerDevice(User $user, array $attrs): array
    {
        return DB::transaction(function () use ($user, $attrs) {
            $tenant = $user->tenant;

            // Looked up by device_uuid ALONE (not also scoped to this
            // tenant) because device_uuid carries a table-wide unique
            // constraint (see the mobile_devices migration's docblock: one
            // physical app-install is meant to belong to exactly one
            // tenant for its lifetime). Scoping this lookup to the current
            // tenant let a uuid already owned by a DIFFERENT tenant fall
            // through to the create() below and crash on that unique
            // constraint with an unhandled 500 instead of the intended,
            // already-documented "revoked blocks unconditionally"
            // behavior — reproduced 2026-09-04 re-registering the team's
            // TECNO CK7n test phone (uuid previously revoked under an
            // earlier test tenant) against a different tenant login.
            $device = MobileDevice::withoutGlobalScope('tenant')
                ->where('device_uuid', $attrs['device_uuid'])
                ->first();

            abort_if($device && $device->status === MobileDevice::STATUS_REVOKED, 403, 'ডিভাইসটি বাতিল করা হয়েছে।');

            abort_if(
                $device && (int) $device->tenant_id !== (int) $tenant->id,
                409,
                'ডিভাইসটি ইতিমধ্যে অন্য একটি অ্যাকাউন্টে নিবন্ধিত। এই অ্যাকাউন্টে ব্যবহার করতে হলে অ্যাপ আনইনস্টল করে আবার ইনস্টল করুন।'
            );

            $attributes = [
                'user_id' => $user->id,
                'platform' => $attrs['platform'] ?? 'android',
                'device_model' => $attrs['device_model'] ?? null,
                'os_version' => $attrs['os_version'] ?? null,
                'app_version' => $attrs['app_version'] ?? null,
                'status' => MobileDevice::STATUS_OFF,
                'verified_at' => now(),
                'approved_at' => now(),
                'approved_by_super_admin_id' => null,
                'remote_support_enabled' => true,
            ];

            if ($device) {
                $device->fill($attributes);
                $device->save();
            } else {
                $device = MobileDevice::create($attributes + [
                    'tenant_id' => $tenant->id,
                    'device_uuid' => $attrs['device_uuid'],
                ]);
            }

            // Separate, device-scoped credential — see MobileDevice's
            // docblock. Any previous device token for this row is revoked
            // first so a re-registration can't leave two live credentials.
            if ($device->credential_token_id) {
                $user->tokens()->where('id', $device->credential_token_id)->delete();
            }
            $token = $user->createToken(
                'device:'.$device->device_uuid,
                ['device:heartbeat', 'device:signal']
            );
            $device->credential_token_id = $token->accessToken->id;
            $device->save();

            $this->log(
                tenantId: $tenant->id,
                deviceId: $device->id,
                eventType: 'device_registered_auto_approved',
                actorType: 'device',
            );

            return ['device' => $device, 'device_token' => $token->plainTextToken];
        });
    }

    public function revokeDevice(MobileDevice $device, SuperAdmin $admin, ?string $reason = null): MobileDevice
    {
        return DB::transaction(function () use ($device, $admin, $reason) {
            $device->status = MobileDevice::STATUS_REVOKED;
            $device->remote_support_enabled = false;
            $device->revoked_by_super_admin_id = $admin->id;
            $device->revoked_at = now();
            $device->revoke_reason = $reason;
            $device->save();

            if ($device->credential_token_id) {
                DB::table('personal_access_tokens')->where('id', $device->credential_token_id)->delete();
            }

            RemoteSupportSession::where('mobile_device_id', $device->id)
                ->where('status', '!=', RemoteSupportSession::STATUS_ENDED)
                ->get()
                ->each(fn (RemoteSupportSession $s) => $this->stopSession($s, actorType: 'system', reason: 'device_offline'));

            $this->log(
                tenantId: $device->tenant_id,
                deviceId: $device->id,
                eventType: 'device_revoked',
                actorType: 'super_admin',
                actorId: $admin->id,
                note: $reason,
            );

            return $device;
        });
    }

    /**
     * Per-device kill switch, independent of full revocation — see
     * MobileDevice migration's docblock.
     *
     * Re-enabling recomputes readiness from the device's own last-reported
     * permissions/foreground-service state (the same computation
     * recordHeartbeat uses) instead of always resetting to on_not_ready —
     * found while diagnosing a device stuck showing not-ready right after
     * being re-enabled despite its last heartbeat having already reported
     * every precondition satisfied. This does NOT invent a new readiness
     * signal or fake anything: it's the exact same stored data a heartbeat
     * would have used, just not thrown away by this action.
     */
    public function toggleDevice(MobileDevice $device, bool $enabled, SuperAdmin $admin): MobileDevice
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 409, 'ডিভাইসটি বাতিল করা হয়েছে।');

        return DB::transaction(function () use ($device, $enabled, $admin) {
            $device->remote_support_enabled = $enabled;
            if (! $enabled) {
                $device->status = MobileDevice::STATUS_OFF;
            } elseif ($device->status === MobileDevice::STATUS_OFF) {
                $device->status = $this->computeReadiness($device);
            }
            $device->save();

            $this->log(
                tenantId: $device->tenant_id,
                deviceId: $device->id,
                eventType: $enabled ? 'device_enabled' : 'device_disabled',
                actorType: 'super_admin',
                actorId: $admin->id,
            );

            return $device;
        });
    }

    /**
     * Recomputes `status` among off/on_not_ready/on_ready from the
     * reported preconditions — never trusts the device to self-report
     * "ready" directly (docs/permission-flow.md: `ON / READY` never means
     * capture is pre-authorized, only that every *other* precondition is
     * satisfied).
     */
    public function recordHeartbeat(MobileDevice $device, array $data): MobileDevice
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);

        $device->last_seen_at = now();
        $device->battery_pct = $data['battery_pct'] ?? $device->battery_pct;
        $device->charging = $data['charging'] ?? $device->charging;
        $device->network_type = $data['network_type'] ?? $device->network_type;
        $device->foreground_service_running = $data['foreground_service_running'] ?? $device->foreground_service_running;

        if (array_key_exists('permissions', $data)) {
            $device->permissions = $data['permissions'];
        }

        if (! $device->remote_support_enabled) {
            $device->status = $device->status === MobileDevice::STATUS_PENDING_VERIFICATION
                ? $device->status
                : MobileDevice::STATUS_OFF;
        } elseif (in_array($device->status, [MobileDevice::STATUS_OFF, MobileDevice::STATUS_ON_NOT_READY, MobileDevice::STATUS_ON_READY], true)) {
            $device->status = $this->computeReadiness($device);
        }

        $device->save();

        return $device;
    }

    /** Shared by recordHeartbeat and toggleDevice — see toggleDevice's doc comment for why the latter needs this too. */
    private function computeReadiness(MobileDevice $device): string
    {
        $requiredGranted = $this->requiredPermissionsGranted($device->permissions ?? []);

        return ($requiredGranted && $device->foreground_service_running)
            ? MobileDevice::STATUS_ON_READY
            : MobileDevice::STATUS_ON_NOT_READY;
    }

    private function requiredPermissionsGranted(array $permissions): bool
    {
        foreach (['notifications', 'battery_optimization_exempt'] as $key) {
            if (empty($permissions[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Server-side eligibility only covers three of the four trust tiers.
     * The fourth — MediaProjection consent — is inherently on-device and
     * per-session by Android OS design (docs/permission-flow.md
     * §MediaProjection): this call can authorize a *signaling* session and
     * hand the device a wake/session token, but the device's own system
     * consent dialog is what actually allows capture to start. A session
     * that never receives that on-device consent simply never produces a
     * video track — this service cannot force or skip that step.
     */
    public function startSession(MobileDevice $device, SuperAdmin $admin, bool $includeMicrophone, bool $includeCamera): RemoteSupportSession
    {
        abort_unless($device->tenant->hasRemoteSupportEnabled(), 409, 'এই টেনেন্টের জন্য রিমোট সাপোর্ট চালু নেই।');
        abort_unless($device->isEligibleForSession(), 409, 'ডিভাইসটি এখন রেডি নয়।');

        $existing = RemoteSupportSession::where('mobile_device_id', $device->id)
            ->where('status', '!=', RemoteSupportSession::STATUS_ENDED)
            ->first();

        if ($existing) {
            // A session past its expires_at hard cap was never explicitly
            // stopped (device went offline mid-session, admin closed the
            // tab, etc.) — self-heal it here rather than requiring a
            // scheduled sweep job, so an abandoned session can't
            // permanently block ever starting a new one on this device
            // (RemoteSupportSignal::pushSignal already independently
            // refuses to relay anything through it via isOpen()).
            //
            // isLikelyAbandoned() covers a real, physically-confirmed
            // failure mode isExpired() alone left open for up to the full
            // max_session_minutes: a tenant revoking the Remote Support
            // notification permission makes Android kill the device's
            // whole app process immediately (confirmed via logcat,
            // 2026-08-22, TECNO CK7n — this is OS policy on any runtime
            // permission revocation, not something the app can prevent),
            // before it ever gets a chance to send a 'bye' signal. That
            // leaves a genuinely-dead session stuck at status=active,
            // connected_at=null, 409-blocking every retry until the full
            // expiry window passes — see that model method's doc comment
            // for why "never connected within a short grace window" is a
            // safe, narrow signal for "this one is actually dead", not
            // just slow.
            if ($existing->isExpired() || $existing->isLikelyAbandoned()) {
                $this->stopSession($existing, reason: $existing->isExpired() ? 'expired' : 'abandoned_no_connection', actorType: 'system');
            } else {
                abort(409, 'এই ডিভাইসে ইতিমধ্যে একটি সেশন চলছে।');
            }
        }

        return DB::transaction(function () use ($device, $admin, $includeMicrophone, $includeCamera) {
            $session = RemoteSupportSession::create([
                'tenant_id' => $device->tenant_id,
                'mobile_device_id' => $device->id,
                'started_by_super_admin_id' => $admin->id,
                'status' => RemoteSupportSession::STATUS_ACTIVE,
                'session_token' => Str::random(64),
                'include_microphone' => $includeMicrophone,
                'include_camera' => $includeCamera,
                'started_at' => now(),
                'expires_at' => now()->addMinutes((int) config('remote_support.max_session_minutes')),
            ]);

            $this->log(
                tenantId: $device->tenant_id,
                deviceId: $device->id,
                sessionId: $session->id,
                eventType: 'session_started',
                actorType: 'super_admin',
                actorId: $admin->id,
            );

            return $session;
        });
    }

    public function stopSession(RemoteSupportSession $session, ?string $reason = 'stopped_by_admin', string $actorType = 'super_admin', ?int $actorId = null): RemoteSupportSession
    {
        if ($session->status === RemoteSupportSession::STATUS_ENDED) {
            return $session;
        }

        return DB::transaction(function () use ($session, $reason, $actorType, $actorId) {
            $session->status = RemoteSupportSession::STATUS_ENDED;
            $session->ended_at = now();
            $session->end_reason = $reason;
            $session->save();

            $this->log(
                tenantId: $session->tenant_id,
                deviceId: $session->mobile_device_id,
                sessionId: $session->id,
                eventType: 'session_ended',
                actorType: $actorType,
                actorId: $actorId,
                note: $reason,
            );

            return $session;
        });
    }

    public function pushSignal(RemoteSupportSession $session, string $sender, string $type, string $payload): RemoteSupportSignal
    {
        abort_unless($session->isOpen(), 409, 'সেশনটি আর সক্রিয় নেই।');

        $signal = RemoteSupportSignal::create([
            'tenant_id' => $session->tenant_id,
            'remote_support_session_id' => $session->id,
            'sender' => $sender,
            'type' => $type,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        if ($sender === RemoteSupportSignal::SENDER_ADMIN && $type === 'answer' && ! $session->connected_at) {
            $session->connected_at = now();
            $session->save();
            $this->log($session->tenant_id, $session->mobile_device_id, $session->id, 'session_connected', 'admin');
        }

        if ($type === 'bye') {
            $this->stopSession($session, reason: $sender === 'device' ? 'device_declined' : 'stopped_by_admin', actorType: $sender);
        }

        return $signal;
    }

    /** @return Collection<int, RemoteSupportSignal> */
    public function pollSignals(RemoteSupportSession $session, int $sinceId, string $forSide)
    {
        $otherSide = $forSide === RemoteSupportSignal::SENDER_ADMIN
            ? RemoteSupportSignal::SENDER_DEVICE
            : RemoteSupportSignal::SENDER_ADMIN;

        return $session->signals()
            ->where('sender', $otherSide)
            ->where('id', '>', $sinceId)
            ->orderBy('id')
            ->get();
    }

    public function iceServers(): array
    {
        $servers = collect(config('remote_support.stun_urls'))
            ->map(fn (string $url) => ['urls' => $url])
            ->values()
            ->all();

        if (config('remote_support.turn_url')) {
            $servers[] = [
                'urls' => config('remote_support.turn_url'),
                'username' => config('remote_support.turn_username'),
                'credential' => config('remote_support.turn_credential'),
            ];
        }

        return $servers;
    }

    public function log(
        ?int $tenantId,
        ?int $deviceId = null,
        ?int $sessionId = null,
        string $eventType = '',
        string $actorType = 'system',
        ?int $actorId = null,
        ?string $note = null,
    ): DeviceEvent {
        return DeviceEvent::create([
            'tenant_id' => $tenantId,
            'mobile_device_id' => $deviceId,
            'remote_support_session_id' => $sessionId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
