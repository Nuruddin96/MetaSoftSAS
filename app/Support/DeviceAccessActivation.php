<?php

namespace App\Support;

/**
 * The shared "app consent × Android access -> activation" rule, used by
 * BOTH Remote Support (MobileDevice::computeActivationStatus — left
 * untouched/duplicated here rather than refactored, since that method is
 * already tested and deployed to production) and Device Intelligence
 * (DeviceIntelligenceFeatureState::computeActivationStatus). Kept in one
 * place for NEW callers so the rule itself never drifts, without touching
 * MobileDevice's own already-shipped method.
 *
 * Mirrors remoteSupportActivationFor() in
 * remote_support_access_state.dart (Remote Support) and its Device
 * Intelligence Flutter counterpart exactly — see
 * docs/device-intelligence-architecture.md.
 */
class DeviceAccessActivation
{
    public const CONSENT_NOT_ASKED = 'not_asked';

    public const CONSENT_ENABLED = 'enabled';

    public const CONSENT_DISABLED = 'disabled';

    public const ACCESS_GRANTED = 'granted';

    public const ACCESS_DENIED = 'denied';

    public const ACCESS_RESTRICTED = 'restricted';

    public const ACCESS_NOT_SUPPORTED = 'not_supported';

    public const ACCESS_NOT_REQUESTED = 'not_requested';

    public const ACTIVATION_INACTIVE = 'inactive';

    public const ACTIVATION_WAITING_FOR_ANDROID_ACCESS = 'waiting_for_android_access';

    public const ACTIVATION_DISABLED_BY_TENANT = 'disabled_by_tenant';

    public const ACTIVATION_ACTIVE = 'active';

    /**
     * @param  array<string, string>  $androidAccess  keyed by $requiredKeys entries
     * @param  string[]  $requiredKeys  which android_access keys must be `granted` for activation — a feature with no required keys (e.g. one that's active as soon as consent is on, with no distinct system access of its own) passes an empty array.
     */
    public static function compute(string $consentStatus, array $androidAccess, array $requiredKeys): string
    {
        $requiredGranted = true;
        foreach ($requiredKeys as $key) {
            if (($androidAccess[$key] ?? self::ACCESS_NOT_REQUESTED) !== self::ACCESS_GRANTED) {
                $requiredGranted = false;
                break;
            }
        }

        if ($consentStatus === self::CONSENT_ENABLED) {
            return $requiredGranted ? self::ACTIVATION_ACTIVE : self::ACTIVATION_WAITING_FOR_ANDROID_ACCESS;
        }

        return $requiredGranted ? self::ACTIVATION_DISABLED_BY_TENANT : self::ACTIVATION_INACTIVE;
    }
}
