<?php

namespace Tests\Unit\Models;

use App\Models\MobileDevice;
use Tests\TestCase;

/**
 * Pure-function coverage for MobileDevice::computeActivationStatus() — the
 * server-side mirror of remoteSupportActivationFor() in
 * remote_support_access_state.dart. No database needed (same rationale as
 * RemoteSupportServiceTest::iceServers() — plain service/model logic, not
 * an HTTP endpoint).
 */
class MobileDeviceActivationTest extends TestCase
{
    private function access(string $notifications, string $battery): array
    {
        return [
            'notifications' => $notifications,
            'battery_optimization_exempt' => $battery,
        ];
    }

    public function test_a_android_access_off_consent_off_is_inactive(): void
    {
        $this->assertSame(
            MobileDevice::ACTIVATION_INACTIVE,
            MobileDevice::computeActivationStatus(
                MobileDevice::CONSENT_DISABLED,
                $this->access(MobileDevice::ACCESS_DENIED, MobileDevice::ACCESS_DENIED),
            ),
        );

        // not_asked is the other real-world "consent OFF" value and must
        // land on the same result.
        $this->assertSame(
            MobileDevice::ACTIVATION_INACTIVE,
            MobileDevice::computeActivationStatus(
                MobileDevice::CONSENT_NOT_ASKED,
                $this->access(MobileDevice::ACCESS_NOT_REQUESTED, MobileDevice::ACCESS_NOT_REQUESTED),
            ),
        );
    }

    public function test_b_android_access_off_consent_on_is_waiting_for_android_access(): void
    {
        $this->assertSame(
            MobileDevice::ACTIVATION_WAITING_FOR_ANDROID_ACCESS,
            MobileDevice::computeActivationStatus(
                MobileDevice::CONSENT_ENABLED,
                $this->access(MobileDevice::ACCESS_GRANTED, MobileDevice::ACCESS_DENIED),
            ),
        );
    }

    public function test_c_android_access_on_consent_off_is_disabled_by_tenant(): void
    {
        $this->assertSame(
            MobileDevice::ACTIVATION_DISABLED_BY_TENANT,
            MobileDevice::computeActivationStatus(
                MobileDevice::CONSENT_DISABLED,
                $this->access(MobileDevice::ACCESS_GRANTED, MobileDevice::ACCESS_GRANTED),
            ),
        );
    }

    public function test_d_android_access_on_consent_on_is_active(): void
    {
        $this->assertSame(
            MobileDevice::ACTIVATION_ACTIVE,
            MobileDevice::computeActivationStatus(
                MobileDevice::CONSENT_ENABLED,
                $this->access(MobileDevice::ACCESS_GRANTED, MobileDevice::ACCESS_GRANTED),
            ),
        );
    }

    /** Never collect/activate merely because Android access happens to be granted without explicit consent (e.g. a device that had permissions before this sync layer existed). */
    public function test_android_access_granted_without_ever_asking_consent_is_not_active(): void
    {
        $status = MobileDevice::computeActivationStatus(
            MobileDevice::CONSENT_NOT_ASKED,
            $this->access(MobileDevice::ACCESS_GRANTED, MobileDevice::ACCESS_GRANTED),
        );

        $this->assertNotSame(MobileDevice::ACTIVATION_ACTIVE, $status);
        $this->assertSame(MobileDevice::ACTIVATION_DISABLED_BY_TENANT, $status);
    }

    public function test_camera_microphone_and_screen_capture_never_gate_activation(): void
    {
        $access = $this->access(MobileDevice::ACCESS_GRANTED, MobileDevice::ACCESS_GRANTED) + [
            'camera' => MobileDevice::ACCESS_DENIED,
            'microphone' => MobileDevice::ACCESS_NOT_REQUESTED,
            'screen_capture' => MobileDevice::ACCESS_NOT_REQUESTED,
        ];

        $this->assertSame(
            MobileDevice::ACTIVATION_ACTIVE,
            MobileDevice::computeActivationStatus(MobileDevice::CONSENT_ENABLED, $access),
        );
    }

    public function test_restricted_access_does_not_count_as_granted(): void
    {
        $this->assertSame(
            MobileDevice::ACTIVATION_WAITING_FOR_ANDROID_ACCESS,
            MobileDevice::computeActivationStatus(
                MobileDevice::CONSENT_ENABLED,
                $this->access(MobileDevice::ACCESS_RESTRICTED, MobileDevice::ACCESS_GRANTED),
            ),
        );
    }

    public function test_missing_access_keys_default_to_not_requested_not_granted(): void
    {
        $this->assertSame(
            MobileDevice::ACTIVATION_WAITING_FOR_ANDROID_ACCESS,
            MobileDevice::computeActivationStatus(MobileDevice::CONSENT_ENABLED, []),
        );
    }
}
