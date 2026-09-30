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
     * A required Android access this build/OS simply cannot provide (a
     * required key reported `not_supported` — e.g. `notification_listener`
     * on the `direct` APK flavor, whose manifest removes the listener
     * service; see the Flutter side's `isNotificationListenerAvailable`).
     * Deliberately DISTINCT from `waiting_for_android_access`: there is no
     * system permission screen the tenant could open to resolve it, so the
     * UI must never tell them to "wait for Android permission". Mirrors the
     * Flutter `awaitingGrantableAccess` rule, which already excludes
     * `not_supported` keys from "still needs granting".
     */
    public const ACTIVATION_NOT_SUPPORTED = 'not_supported';

    /**
     * @param  array<string, string>  $androidAccess  keyed by $requiredKeys entries
     * @param  string[]  $requiredKeys  which android_access keys must be `granted` for activation — a feature with no required keys (e.g. one that's active as soon as consent is on, with no distinct system access of its own) passes an empty array.
     */
    public static function compute(string $consentStatus, array $androidAccess, array $requiredKeys): string
    {
        $requiredGranted = true;
        $anyNotSupported = false;
        foreach ($requiredKeys as $key) {
            $status = $androidAccess[$key] ?? self::ACCESS_NOT_REQUESTED;
            if ($status !== self::ACCESS_GRANTED) {
                $requiredGranted = false;
            }
            if ($status === self::ACCESS_NOT_SUPPORTED) {
                $anyNotSupported = true;
            }
        }

        // A capability the installed build/OS can't provide is neither
        // active nor a matter of the tenant granting a permission — report
        // it distinctly, independent of consent, so the status UI can say
        // "not supported by this build" instead of "waiting for Android
        // permission" for an access screen that can't exist here.
        if (! $requiredGranted && $anyNotSupported) {
            return self::ACTIVATION_NOT_SUPPORTED;
        }

        if ($consentStatus === self::CONSENT_ENABLED) {
            return $requiredGranted ? self::ACTIVATION_ACTIVE : self::ACTIVATION_WAITING_FOR_ANDROID_ACCESS;
        }

        return $requiredGranted ? self::ACTIVATION_DISABLED_BY_TENANT : self::ACTIVATION_INACTIVE;
    }
}
