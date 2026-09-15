<?php

namespace App\Http\Controllers\Api\Mobile;

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
 * Mirrors Tenant\WhatsAppConnectController exactly -- reuses
 * WhatsAppOAuthService for every state/Graph/OAuth call, no duplicated
 * business logic. Unlike Facebook Connect, Meta's Embedded Signup is a JS
 * SDK popup (FB.login() + a window 'message' listener for the
 * WA_EMBEDDED_SIGNUP event), not a plain OAuth redirect -- there is no
 * mintable authorization_url to hand off to an external browser the way
 * FacebookConnectController::connectUrl() does. connectConfig() instead
 * hands the Flutter app everything it needs (App ID/config_id/graph
 * version/state) to run that same JS SDK flow inside an in-app WebView
 * (assets/whatsapp_embedded_signup.html mirrors settings.blade.php's
 * script block line-for-line), which then posts the popup's result
 * (code/waba_id/phone_number_id/business_id) back to complete() below --
 * the exact same fields, same order of verification, same security
 * checks (state validation, tenant match, token exchange, live Graph
 * phone-number re-verification, cross-tenant hijack rejection) as the web
 * controller's complete(), just JSON in/out instead of a form POST +
 * redirect. No access/Page/WABA token is ever included in a response from
 * this controller.
 */
class WhatsAppConnectController extends Controller
{
    protected const NOT_READY_MESSAGE = 'WhatsApp ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি — একটু পর আবার চেষ্টা করুন।';

    /**
     * Everything the WebView-hosted Embedded Signup page needs to call
     * FB.login() -- mirrors the data-* attributes
     * Tenant\SettingController::index() puts on the Connect/Reconnect
     * button. state is only minted (via currentOrNewState(), which reuses
     * an existing unexpired/unused row rather than always creating a new
     * one) when the tenant doesn't already have a fully active number --
     * same "never mint when meaningless" rule as the web page.
     */
    public function connectConfig(Request $request, WhatsAppOAuthService $wa)
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return response()->json(['ready' => false, 'message' => self::NOT_READY_MESSAGE], 503);
        }

        $tenant = app('currentTenant');

        if (! $tenant->plan?->hasFeature('whatsapp')) {
            return response()->json(['ready' => false, 'message' => 'এই ফিচারটি আপনার বর্তমান প্ল্যানে অন্তর্ভুক্ত নেই।'], 403);
        }

        $hasFullyActiveNumber = WhatsAppPhoneNumber::where('is_active', 1)->where('status', 'active')->exists();

        if ($hasFullyActiveNumber) {
            return response()->json(['ready' => true, 'connected' => true]);
        }

        $state = $wa->currentOrNewState($tenant, $request->user());

        return response()->json([
            'ready' => true,
            'connected' => false,
            'app_id' => config('facebook.app_id'),
            'config_id' => config('whatsapp.embedded_signup_config_id'),
            'graph_version' => config('whatsapp.graph_version'),
            'state' => $state->state,
        ]);
    }

    /** Current connection + phone-number summary -- never includes a token. */
    public function status()
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return response()->json(['ready' => false, 'account' => null, 'phones' => []]);
        }

        $account = WhatsAppBusinessAccount::first();
        $phones = WhatsAppPhoneNumber::orderByDesc('id')->get();

        return response()->json([
            'ready' => true,
            'account' => $account ? ['business_name' => $account->business_name] : null,
            'phones' => $phones->map(fn (WhatsAppPhoneNumber $p) => $this->presentPhone($p))->values(),
        ]);
    }

    /**
     * Mirrors Tenant\WhatsAppConnectController::complete() exactly -- see
     * that method's own extensive docblocks/comments for the reasoning
     * behind every check below (state/tenant match, token exchange, live
     * phone-number re-verification including the Coexistence
     * no-phone_number_id case, cross-tenant hijack rejection, webhook
     * subscription). Kept in the same order deliberately, so a future
     * change to the web method's security logic has an obvious mobile
     * counterpart to update.
     */
    public function complete(Request $request, WhatsAppOAuthService $wa)
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return response()->json(['message' => self::NOT_READY_MESSAGE], 503);
        }

        $data = $request->validate([
            'state' => 'required|string',
            'code' => 'required|string',
            'waba_id' => 'required|string|max:64',
            'phone_number_id' => 'nullable|string|max:64',
            'business_id' => 'nullable|string|max:64',
        ]);

        $tenant = app('currentTenant');

        try {
            $state = $wa->validateAndConsumeState($data['state'], $request->user()->id);
        } catch (WhatsAppOAuthException $e) {
            Log::warning('WhatsApp connect: state validation failed (mobile).', [
                'reason' => $e->reason,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'এই WhatsApp সংযোগ অনুরোধ যাচাই করা যায়নি। আবার চেষ্টা করুন।'], 422);
        }

        if ((int) $state->tenant_id !== $tenant->id) {
            Log::error('WhatsApp connect: state tenant_id did not match the authenticated tenant (mobile).', [
                'state_tenant_id' => $state->tenant_id,
                'authenticated_tenant_id' => $tenant->id,
            ]);

            return response()->json(['message' => 'এই WhatsApp সংযোগ অনুরোধ যাচাই করা যায়নি। আবার চেষ্টা করুন।'], 422);
        }

        try {
            $shortLived = $wa->exchangeCodeForAccessToken($data['code']);

            if (isset($shortLived['expires_in'])) {
                $exchanged = $wa->exchangeForLongLivedToken($shortLived['access_token']);
                $token = $exchanged['access_token'];
                $tokenExpiresIn = $exchanged['expires_in'];
            } else {
                $token = $shortLived['access_token'];
                $tokenExpiresIn = null;
            }
        } catch (WhatsAppGraphException $e) {
            Log::error('WhatsApp connect: token exchange failed (mobile).', [
                'tenant_id' => $tenant->id,
                'error' => $e->errorPayload(),
            ]);

            return response()->json(['message' => 'WhatsApp টোকেন যাচাই ব্যর্থ হয়েছে, আবার চেষ্টা করুন।'], 422);
        } catch (ConnectionException $e) {
            Log::error('WhatsApp connect: connection failure during token exchange (mobile).', ['tenant_id' => $tenant->id]);

            return response()->json(['message' => 'WhatsApp-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।'], 422);
        }

        try {
            if (! empty($data['phone_number_id'])) {
                $phoneDetails = $wa->findPhoneNumber($data['waba_id'], $data['phone_number_id'], $token);
            } else {
                $phones = $wa->listPhoneNumbers($data['waba_id'], $token);

                if (count($phones) > 1) {
                    Log::warning('WhatsApp connect: coexistence completion found more than one phone number on the WABA, refusing to guess (mobile).', [
                        'tenant_id' => $tenant->id,
                        'waba_id' => $data['waba_id'],
                        'phone_count' => count($phones),
                    ]);

                    return response()->json(['message' => 'এই Business Account-এ একাধিক নম্বর পাওয়া গেছে — কোনটি কানেক্ট করবেন তা নির্ধারণ করা যায়নি। আবার চেষ্টা করুন।'], 422);
                }

                $phoneDetails = $phones[0] ?? null;
            }
        } catch (WhatsAppGraphException $e) {
            Log::error('WhatsApp connect: phone number verification failed (mobile).', [
                'tenant_id' => $tenant->id,
                'error' => $e->errorPayload(),
            ]);

            return response()->json(['message' => 'WhatsApp নম্বর যাচাই ব্যর্থ হয়েছে, আবার চেষ্টা করুন।'], 422);
        } catch (ConnectionException $e) {
            Log::error('WhatsApp connect: connection failure during phone number verification (mobile).', ['tenant_id' => $tenant->id]);

            return response()->json(['message' => 'WhatsApp-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।'], 422);
        }

        if (! $phoneDetails) {
            Log::warning('WhatsApp connect: no verifiable phone number for this connection attempt (mobile).', [
                'tenant_id' => $tenant->id,
                'waba_id' => $data['waba_id'],
                'phone_number_id' => $data['phone_number_id'] ?? null,
            ]);

            return response()->json(['message' => 'এই WhatsApp নম্বরটি নির্বাচিত Business Account-এ পাওয়া যায়নি।'], 422);
        }

        $phoneNumberId = $phoneDetails['id'];

        $wabaClaimedElsewhere = WhatsAppBusinessAccount::withoutGlobalScopes()
            ->where('waba_id', $data['waba_id'])
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();
        $phoneClaimedElsewhere = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('phone_number_id', $phoneNumberId)
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($wabaClaimedElsewhere || $phoneClaimedElsewhere) {
            return response()->json(['message' => 'এই WhatsApp Business Account বা নম্বর ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।'], 422);
        }

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
                    'connected_by_user_id' => $request->user()->id,
                    'waba_id' => $data['waba_id'],
                    'business_id' => $data['business_id'] ?? null,
                    'business_name' => $businessName,
                    'user_access_token' => $token,
                    'token_expires_at' => $tokenExpiresIn !== null ? now()->addSeconds($tokenExpiresIn) : null,
                    'granted_scopes' => 'whatsapp_business_management,whatsapp_business_messaging',
                ]
            );

            $phoneNumber = WhatsAppPhoneNumber::withoutGlobalScopes()->updateOrCreate(
                ['phone_number_id' => $phoneNumberId],
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
            if ($e->getCode() === '23000') {
                return response()->json(['message' => 'এই WhatsApp Business Account বা নম্বর ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।'], 422);
            }

            throw $e;
        }

        try {
            $subscribed = $wa->subscribeWabaToWebhook($data['waba_id'], $token);
        } catch (WhatsAppGraphException $e) {
            Log::error('WhatsApp connect: WABA webhook subscription failed (mobile).', [
                'tenant_id' => $tenant->id,
                'waba_id' => $data['waba_id'],
                'error' => $e->errorPayload(),
            ]);
            $subscribed = false;
        } catch (ConnectionException $e) {
            Log::error('WhatsApp connect: connection failure during webhook subscription (mobile).', ['tenant_id' => $tenant->id]);
            $subscribed = false;
        }

        $phoneNumber->update([
            'status' => $subscribed ? 'active' : 'subscription_failed',
            'subscribed_at' => $subscribed ? now() : null,
        ]);

        return response()->json([
            'ok' => $subscribed,
            'phone' => $this->presentPhone($phoneNumber->fresh()),
            'message' => $subscribed
                ? 'WhatsApp কানেক্ট ও সাবস্ক্রাইব হয়েছে।'
                : 'নম্বর কানেক্ট হয়েছে কিন্তু ওয়েবহুক সাবস্ক্রিপশন ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
        ]);
    }

    /** Mirrors Tenant\WhatsAppConnectController::disconnect() exactly. */
    public function disconnect(int $phone, WhatsAppOAuthService $wa)
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return response()->json(['message' => self::NOT_READY_MESSAGE], 503);
        }

        $phoneNumber = WhatsAppPhoneNumber::with('businessAccount')->findOrFail($phone);

        $siblingActiveNumbers = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('whatsapp_business_account_id', $phoneNumber->whatsapp_business_account_id)
            ->where('id', '!=', $phoneNumber->id)
            ->where('is_active', 1)
            ->exists();

        if (! $siblingActiveNumbers && $phoneNumber->businessAccount) {
            try {
                $wa->unsubscribeWabaFromWebhook($phoneNumber->businessAccount->waba_id, $phoneNumber->businessAccount->user_access_token);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp disconnect: unsubscribe on disconnect failed (mobile, disconnecting locally anyway).', [
                    'waba_id' => $phoneNumber->businessAccount->waba_id,
                    'exception' => get_class($e),
                ]);
            }
        }

        $phoneNumber->update([
            'is_active' => false,
            'disconnected_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    protected function presentPhone(WhatsAppPhoneNumber $p): array
    {
        return [
            'id' => $p->id,
            'display_phone_number' => $p->display_phone_number,
            'verified_name' => $p->verified_name,
            'quality_rating' => $p->quality_rating,
            'status' => $p->status,
            'is_active' => (bool) $p->is_active,
        ];
    }
}
