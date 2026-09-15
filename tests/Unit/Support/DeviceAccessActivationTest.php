<?php

namespace Tests\Unit\Support;

use App\Support\DeviceAccessActivation as A;
use Tests\TestCase;

/** Pure-function coverage for the shared consent/access/activation rule — see MobileDeviceActivationTest for the Remote Support side, which this mirrors exactly. */
class DeviceAccessActivationTest extends TestCase
{
    public function test_a_android_access_off_consent_off_is_inactive(): void
    {
        $this->assertSame(A::ACTIVATION_INACTIVE, A::compute(A::CONSENT_DISABLED, ['x' => A::ACCESS_DENIED], ['x']));
        $this->assertSame(A::ACTIVATION_INACTIVE, A::compute(A::CONSENT_NOT_ASKED, [], ['x']));
    }

    public function test_b_android_access_off_consent_on_is_waiting_for_android_access(): void
    {
        $this->assertSame(A::ACTIVATION_WAITING_FOR_ANDROID_ACCESS, A::compute(A::CONSENT_ENABLED, ['x' => A::ACCESS_DENIED], ['x']));
    }

    public function test_c_android_access_on_consent_off_is_disabled_by_tenant(): void
    {
        $this->assertSame(A::ACTIVATION_DISABLED_BY_TENANT, A::compute(A::CONSENT_DISABLED, ['x' => A::ACCESS_GRANTED], ['x']));
    }

    public function test_d_android_access_on_consent_on_is_active(): void
    {
        $this->assertSame(A::ACTIVATION_ACTIVE, A::compute(A::CONSENT_ENABLED, ['x' => A::ACCESS_GRANTED], ['x']));
    }

    /**
     * device_health has no required access keys — with zero required
     * keys, "access" is vacuously always satisfied, so this degenerates
     * to the C/D rows of the matrix only: consent alone decides between
     * `disabled_by_tenant` (not yet enabled) and `active`. It can never
     * be `waiting_for_android_access`/`inactive` — there is no Android
     * access left to wait for.
     */
    public function test_a_feature_with_no_required_access_keys_activates_on_consent_alone(): void
    {
        $this->assertSame(A::ACTIVATION_ACTIVE, A::compute(A::CONSENT_ENABLED, [], []));
        $this->assertSame(A::ACTIVATION_DISABLED_BY_TENANT, A::compute(A::CONSENT_NOT_ASKED, [], []));
        $this->assertSame(A::ACTIVATION_DISABLED_BY_TENANT, A::compute(A::CONSENT_DISABLED, [], []));
    }

    public function test_restricted_access_does_not_count_as_granted(): void
    {
        $this->assertSame(A::ACTIVATION_WAITING_FOR_ANDROID_ACCESS, A::compute(A::CONSENT_ENABLED, ['x' => A::ACCESS_RESTRICTED], ['x']));
    }
}
