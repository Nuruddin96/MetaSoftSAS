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
use Illuminate\Validation\ValidationException;

/**
 * Single place every Remote Support state mutation goes through — tenant
 * enable/disable, device registration/approval/revocation, heartbeats,
 * and session lifecycle — so every transition is validated against the
 * three-tier trust model (docs/security-model.md §3) and logged as a
 * DeviceEvent (docs/device-lifecycle.md "Session audit trail") in the same
 * transaction as the change it describes. Controllers never write to these
 * tables directly.
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
     * this point). Idempotent on `device_uuid`: re-registering an already-
     * approved device (e.g. app reinstall) does NOT silently restore
     * trust — it resets to `pending_verification` and a fresh code, same
     * as a brand-new device, per docs/security-model.md's "not
     * reinstallable to bypass revocation" intent.
     */
    public function registerDevice(User $user, array $attrs): array
    {
        return DB::transaction(function () use ($user, $attrs) {
            $tenant = $user->tenant;

            $device = MobileDevice::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('device_uuid', $attrs['device_uuid'])
                ->first();

            $code = strtoupper(Str::random(6));

            if ($device) {
                abort_if($device->status === MobileDevice::STATUS_REVOKED && $device->user_id !== $user->id, 403);

                $device->fill([
                    'user_id' => $user->id,
                    'platform' => $attrs['platform'] ?? 'android',
                    'device_model' => $attrs['device_model'] ?? null,
                    'os_version' => $attrs['os_version'] ?? null,
                    'app_version' => $attrs['app_version'] ?? null,
                    'status' => MobileDevice::STATUS_PENDING_VERIFICATION,
                    'verification_code' => $code,
                    'verified_at' => null,
                    'remote_support_enabled' => false,
                ]);
                $device->save();
            } else {
                $device = MobileDevice::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'device_uuid' => $attrs['device_uuid'],
                    'platform' => $attrs['platform'] ?? 'android',
                    'device_model' => $attrs['device_model'] ?? null,
                    'os_version' => $attrs['os_version'] ?? null,
                    'app_version' => $attrs['app_version'] ?? null,
                    'status' => MobileDevice::STATUS_PENDING_VERIFICATION,
                    'verification_code' => $code,
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
                eventType: 'device_registered',
                actorType: 'device',
            );

            return ['device' => $device, 'device_token' => $token->plainTextToken];
        });
    }

    public function approveDevice(MobileDevice $device, SuperAdmin $admin, string $verificationCode): MobileDevice
    {
        if (! hash_equals((string) $device->verification_code, $verificationCode)) {
            throw ValidationException::withMessages(['verification_code' => ['ভেরিফিকেশন কোড মিলছে না।']]);
        }

        return DB::transaction(function () use ($device, $admin) {
            $device->status = MobileDevice::STATUS_OFF;
            $device->verified_at = now();
            $device->approved_by_super_admin_id = $admin->id;
            $device->approved_at = now();
            $device->remote_support_enabled = true;
            $device->save();

            $this->log(
                tenantId: $device->tenant_id,
                deviceId: $device->id,
                eventType: 'device_approved',
                actorType: 'super_admin',
                actorId: $admin->id,
            );

            return $device;
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

    /** Per-device kill switch, independent of full revocation — see MobileDevice migration's docblock. */
    public function toggleDevice(MobileDevice $device, bool $enabled, SuperAdmin $admin): MobileDevice
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 409, 'ডিভাইসটি বাতিল করা হয়েছে।');

        return DB::transaction(function () use ($device, $enabled, $admin) {
            $device->remote_support_enabled = $enabled;
            if (! $enabled) {
                $device->status = MobileDevice::STATUS_OFF;
            } elseif ($device->status === MobileDevice::STATUS_OFF) {
                $device->status = MobileDevice::STATUS_ON_NOT_READY;
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
            $requiredGranted = $this->requiredPermissionsGranted($device->permissions ?? []);
            $device->status = ($requiredGranted && $device->foreground_service_running)
                ? MobileDevice::STATUS_ON_READY
                : MobileDevice::STATUS_ON_NOT_READY;
        }

        $device->save();

        return $device;
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
            if ($existing->isExpired()) {
                $this->stopSession($existing, reason: 'expired', actorType: 'system');
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

        if ($sender === RemoteSupportSignal::SENDER_DEVICE && $type === 'answer' && ! $session->connected_at) {
            $session->connected_at = now();
            $session->save();
            $this->log($session->tenant_id, $session->mobile_device_id, $session->id, 'session_connected', 'device');
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
