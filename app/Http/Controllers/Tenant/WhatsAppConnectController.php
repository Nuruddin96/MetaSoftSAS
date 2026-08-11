<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\WhatsAppGraphException;
use App\Exceptions\WhatsAppOAuthException;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Services\WhatsApp\WhatsAppOAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * "Connect WhatsApp" via Meta's Embedded Signup. Unlike
 * FacebookConnectController, this has no separate central callback route:
 * Embedded Signup runs entirely inside a JS SDK popup on the already
 * tenant-authenticated Settings page (see resources/views/tenant/settings.
 * blade.php), so there is no browser redirect that would need a
 * pre-registered, non-tenant-prefixed URL the way classic Facebook Login
 * needs. complete() is a normal tenant-panel route, protected by the same
 * auth:tenant/resolve.tenant/check.subscription stack as every other
 * Settings action — app('currentTenant') is genuinely bound here, unlike
 * FacebookOAuthCallbackController's route.
 */
class WhatsAppConnectController extends Controller
{
    protected const NOT_READY_MESSAGE = 'WhatsApp ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি — একটু পর আবার চেষ্টা করুন।';

    /**
     * Called by the Settings page's JS after the Embedded Signup popup
     * finishes (FB.login()'s callback supplies `code`; the WA_EMBEDDED_
     * SIGNUP postMessage event supplies waba_id/phone_number_id/business_id
     * — see settings.blade.php). A normal form POST, not fetch/JSON, so a
     * completed connection is a standard redirect+flash like every other
     * action in this panel.
     */
    public function complete(Request $request, WhatsAppOAuthService $wa)
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return redirect()->route('tenant.settings')->with('error', self::NOT_READY_MESSAGE);
        }

        $data = $request->validate([
            'state' => 'required|string',
            'code' => 'required|string',
            'waba_id' => 'required|string|max:64',
            'phone_number_id' => 'required|string|max:64',
            'business_id' => 'nullable|string|max:64',
        ]);

        $tenant = app('currentTenant');

        try {
            $state = $wa->validateAndConsumeState($data['state'], auth('tenant')->id());
        } catch (WhatsAppOAuthException $e) {
            Log::warning('WhatsApp connect: state validation failed.', [
                'reason' => $e->reason,
                'ip' => $request->ip(),
            ]);

            return redirect()->route('tenant.settings')->with('error', 'এই WhatsApp সংযোগ অনুরোধ যাচাই করা যায়নি। আবার চেষ্টা করুন।');
        }

        // Defense-in-depth beyond the user_id check inside
        // validateAndConsumeState(): unlike Facebook's central callback
        // (which has no app('currentTenant') binding to compare against at
        // all), this route DOES have one, since it lives inside the normal
        // tenant panel stack — so it costs nothing to also confirm the
        // state's own tenant_id agrees with it, rather than trusting the
        // state row as the sole source of truth the way Facebook's flow
        // structurally has to.
        if ((int) $state->tenant_id !== $tenant->id) {
            Log::error('WhatsApp connect: state tenant_id did not match the authenticated panel tenant.', [
                'state_tenant_id' => $state->tenant_id,
                'panel_tenant_id' => $tenant->id,
            ]);

            return redirect()->route('tenant.settings')->with('error', 'এই WhatsApp সংযোগ অনুরোধ যাচাই করা যায়নি। আবার চেষ্টা করুন।');
        }

        try {
            $shortLived = $wa->exchangeCodeForAccessToken($data['code']);
            $longLived = $wa->exchangeForLongLivedToken($shortLived['access_token']);
        } catch (WhatsAppGraphException $e) {
            Log::error('WhatsApp connect: token exchange failed.', [
                'tenant_id' => $tenant->id,
                'error' => $e->errorPayload(),
            ]);

            return redirect()->route('tenant.settings')->with('error', 'WhatsApp টোকেন যাচাই ব্যর্থ হয়েছে, আবার চেষ্টা করুন।');
        } catch (ConnectionException $e) {
            // Deliberately not logging $e->getMessage() — same reasoning as
            // FacebookOAuthCallbackController: a connection exception's
            // message can embed the full failed request URI, which for this
            // call includes client_secret/code as query parameters.
            Log::error('WhatsApp connect: connection failure during token exchange.', ['tenant_id' => $tenant->id]);

            return redirect()->route('tenant.settings')->with('error', 'WhatsApp-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।');
        }

        $token = $longLived['access_token'];

        // The security-critical check this phase exists to enforce: never
        // trust that phone_number_id actually belongs to waba_id just
        // because the browser/postMessage event said so — re-fetch the
        // WABA's real phone numbers from Meta with the token just obtained
        // and confirm the claimed id is genuinely among them.
        try {
            $phoneDetails = $wa->findPhoneNumber($data['waba_id'], $data['phone_number_id'], $token);
        } catch (WhatsAppGraphException $e) {
            Log::error('WhatsApp connect: phone number verification failed.', [
                'tenant_id' => $tenant->id,
                'error' => $e->errorPayload(),
            ]);

            return redirect()->route('tenant.settings')->with('error', 'WhatsApp নম্বর যাচাই ব্যর্থ হয়েছে, আবার চেষ্টা করুন।');
        } catch (ConnectionException $e) {
            Log::error('WhatsApp connect: connection failure during phone number verification.', ['tenant_id' => $tenant->id]);

            return redirect()->route('tenant.settings')->with('error', 'WhatsApp-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।');
        }

        if (! $phoneDetails) {
            Log::warning('WhatsApp connect: claimed phone_number_id is not actually a member of the claimed waba_id.', [
                'tenant_id' => $tenant->id,
                'waba_id' => $data['waba_id'],
                'phone_number_id' => $data['phone_number_id'],
            ]);

            return redirect()->route('tenant.settings')->with('error', 'এই WhatsApp নম্বরটি নির্বাচিত Business Account-এ পাওয়া যায়নি।');
        }

        // Cross-tenant hijack checks (defense-in-depth ahead of the DB's own
        // UNIQUE(waba_id)/UNIQUE(phone_number_id) constraints, same pattern
        // as FacebookConnectController::connect()'s $claimedByAnotherTenant
        // check ahead of facebook_pages' UNIQUE(page_id)).
        $wabaClaimedElsewhere = WhatsAppBusinessAccount::withoutGlobalScopes()
            ->where('waba_id', $data['waba_id'])
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();
        $phoneClaimedElsewhere = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('phone_number_id', $data['phone_number_id'])
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($wabaClaimedElsewhere || $phoneClaimedElsewhere) {
            return redirect()->route('tenant.settings')->with('error', 'এই WhatsApp Business Account বা নম্বর ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।');
        }

        // Cosmetic only — never gates the connection. A failure here must
        // not undo the verification already completed above.
        $businessName = null;
        try {
            $businessName = $wa->getWabaName($data['waba_id'], $token);
        } catch (\Throwable $e) {
            // display-name lookup failing is not worth aborting a verified connection over
        }

        try {
            $account = WhatsAppBusinessAccount::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'connected_by_user_id' => auth('tenant')->id(),
                    'waba_id' => $data['waba_id'],
                    'business_id' => $data['business_id'] ?? null,
                    'business_name' => $businessName,
                    'user_access_token' => $token,
                    'token_expires_at' => isset($longLived['expires_in']) ? now()->addSeconds($longLived['expires_in']) : null,
                    'granted_scopes' => 'whatsapp_business_management,whatsapp_business_messaging',
                ]
            );

            $phoneNumber = WhatsAppPhoneNumber::withoutGlobalScopes()->updateOrCreate(
                ['phone_number_id' => $data['phone_number_id']],
                [
                    'tenant_id' => $tenant->id,
                    'whatsapp_business_account_id' => $account->id,
                    'display_phone_number' => $phoneDetails['display_phone_number'] ?? null,
                    'verified_name' => $phoneDetails['verified_name'] ?? null,
                    'quality_rating' => $phoneDetails['quality_rating'] ?? null,
                    'is_active' => true,
                    'disconnected_at' => null,
                ]
            );
        } catch (QueryException $e) {
            // Race: another tenant claimed this exact waba_id/phone_number_id
            // between the checks above and this write — the DB's UNIQUE
            // constraints are the real guarantee, same pattern as
            // FacebookConnectController::connect().
            if ($e->getCode() === '23000') {
                return redirect()->route('tenant.settings')->with('error', 'এই WhatsApp Business Account বা নম্বর ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।');
            }

            throw $e;
        }

        try {
            $subscribed = $wa->subscribeWabaToWebhook($data['waba_id'], $token);
        } catch (WhatsAppGraphException $e) {
            Log::error('WhatsApp connect: WABA webhook subscription failed.', [
                'tenant_id' => $tenant->id,
                'waba_id' => $data['waba_id'],
                'error' => $e->errorPayload(),
            ]);
            $subscribed = false;
        } catch (ConnectionException $e) {
            Log::error('WhatsApp connect: connection failure during webhook subscription.', ['tenant_id' => $tenant->id]);
            $subscribed = false;
        }

        $phoneNumber->update([
            'status' => $subscribed ? 'active' : 'subscription_failed',
            'subscribed_at' => $subscribed ? now() : null,
        ]);

        return redirect()->route('tenant.settings')->with(
            $subscribed ? 'success' : 'error',
            $subscribed
                ? 'WhatsApp কানেক্ট ও সাবস্ক্রাইব হয়েছে।'
                : 'নম্বর কানেক্ট হয়েছে কিন্তু ওয়েবহুক সাবস্ক্রিপশন ব্যর্থ হয়েছে। আবার চেষ্টা করুন।'
        );
    }

    public function disconnect(int $phone, WhatsAppOAuthService $wa)
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return redirect()->route('tenant.settings')->with('error', self::NOT_READY_MESSAGE);
        }

        // Deliberately NOT an implicitly-bound {phone} route parameter —
        // same reasoning as FacebookConnectController::disconnect():
        // SubstituteBindings (part of the 'web' middleware group wrapping
        // this whole route file) runs before resolve.tenant in this app's
        // route-group nesting, so an implicit binding would resolve before
        // the tenant scope is active. Resolving explicitly here, inside the
        // method body, runs after resolve.tenant has bound currentTenant, so
        // BelongsToTenant's global scope genuinely applies and a foreign
        // tenant's phone number id correctly 404s instead of resolving.
        $phoneNumber = WhatsAppPhoneNumber::with('businessAccount')->findOrFail($phone);

        // Only unsubscribe the WABA from the webhook if this was the last
        // active number under it — the schema allows multiple phone numbers
        // per WABA (chunk26.sql), and unsubscribing at the WABA level would
        // silently stop delivery for any other still-active number sharing
        // it. Phase 4's UI only ever connects one number at a time, but the
        // data model doesn't assume that stays true.
        $siblingActiveNumbers = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('whatsapp_business_account_id', $phoneNumber->whatsapp_business_account_id)
            ->where('id', '!=', $phoneNumber->id)
            ->where('is_active', 1)
            ->exists();

        if (! $siblingActiveNumbers && $phoneNumber->businessAccount) {
            try {
                $wa->unsubscribeWabaFromWebhook($phoneNumber->businessAccount->waba_id, $phoneNumber->businessAccount->user_access_token);
            } catch (\Throwable $e) {
                // Deliberately not logging $e->getMessage() — same token-in-
                // exception-message caution as FacebookConnectController::
                // disconnect(). Disconnecting locally must proceed regardless.
                Log::warning('WhatsApp disconnect: unsubscribe on disconnect failed (disconnecting locally anyway).', [
                    'waba_id' => $phoneNumber->businessAccount->waba_id,
                    'exception' => get_class($e),
                ]);
            }
        }

        // Historical whatsapp_messages rows are untouched — the
        // whatsapp_phone_number_id FK is ON DELETE SET NULL, not cascade,
        // and this action never deletes the phone number row at all, only
        // flips is_active — same "preserve history, allow reconnect later"
        // behavior as FacebookConnectController::disconnect().
        $phoneNumber->update([
            'is_active' => false,
            'disconnected_at' => now(),
        ]);

        return back()->with('success', 'WhatsApp নম্বর ডিসকানেক্ট করা হয়েছে।');
    }
}
