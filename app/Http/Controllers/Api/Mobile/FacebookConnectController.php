<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\FacebookGraphException;
use App\Http\Controllers\Controller;
use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Tenant\FacebookConnectController exactly — reuses
 * FacebookOAuthService for every Graph/OAuth call, no duplicated business
 * logic. The mobile app has no Laravel web session, so where the web
 * controller does a 302 redirect this returns JSON instead (an
 * authorization_url string, or a status/pages payload) — the underlying
 * state-mint/token-exchange/page-verification logic is byte-for-byte the
 * same. The actual OAuth code exchange still happens at the existing,
 * unchanged central callback route (FacebookOAuthCallbackController,
 * registered outside resolve.tenant) — this controller never duplicates
 * that. The client opens the returned authorization_url in an external
 * browser and re-checks status() on return, the same "hand off to an
 * external web flow" pattern Api\Mobile\BillingController's payment_url
 * already established. No access/Page token is ever included in a
 * response from this controller.
 */
class FacebookConnectController extends Controller
{
    protected const NOT_READY_MESSAGE = 'Facebook ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি — একটু পর আবার চেষ্টা করুন।';

    /** Mirrors Tenant\FacebookConnectController::redirect() — mints state, returns the Meta dialog URL instead of redirecting to it. */
    public function connectUrl(Request $request, FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return response()->json(['ready' => false, 'message' => self::NOT_READY_MESSAGE], 503);
        }

        $tenant = app('currentTenant');
        $state = $fb->createState($tenant, $request->user(), 'messenger_mobile');

        return response()->json(['authorization_url' => $fb->authorizationUrl($state)]);
    }

    /** Current connection + active-Page summary — never includes a token. */
    public function status()
    {
        if (! FacebookPage::tablesReady()) {
            return response()->json(['ready' => false, 'connected' => false, 'pages' => []]);
        }

        $connection = FacebookConnection::first();
        $pages = FacebookPage::where('is_active', 1)->get();

        return response()->json([
            'ready' => true,
            'connected' => (bool) $connection,
            'pages' => $pages->map(fn (FacebookPage $p) => $this->presentPage($p))->values(),
        ]);
    }

    /** Mirrors Tenant\FacebookConnectController::pages() — same live /me/accounts fetch, never returns a token. */
    public function pages(FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return response()->json(['message' => self::NOT_READY_MESSAGE], 503);
        }

        $tenant = app('currentTenant');
        $connection = FacebookConnection::first();

        if (! $connection) {
            return response()->json(['message' => 'প্রথমে Facebook কানেক্ট করুন।'], 422);
        }

        try {
            $pages = $fb->getManagedPages($connection->user_access_token);
        } catch (FacebookGraphException $e) {
            $this->handleGraphFailure($e, $tenant->id, $connection->id);

            return response()->json([
                'message' => $e->isInvalidToken()
                    ? 'Facebook সংযোগের মেয়াদ শেষ হয়েছে — আবার কানেক্ট করুন।'
                    : 'Facebook থেকে Page তালিকা আনা যায়নি, একটু পর আবার চেষ্টা করুন।',
            ], 422);
        } catch (ConnectionException $e) {
            Log::error('Facebook: connection failure while listing pages (mobile).', ['tenant_id' => $tenant->id]);

            return response()->json(['message' => 'Facebook-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।'], 422);
        }

        $connectedIds = FacebookPage::where('is_active', 1)->pluck('page_id')->all();

        return response()->json([
            'data' => collect($pages)->map(fn ($p) => [
                'id' => $p['id'],
                'name' => $p['name'] ?? null,
                'category' => $p['category'] ?? null,
                'connected' => in_array($p['id'], $connectedIds, true),
            ])->values(),
        ]);
    }

    /** Mirrors Tenant\FacebookConnectController::connect() exactly (re-verifies against a fresh Graph call, rejects cross-tenant claims). */
    public function connect(string $pageId, FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return response()->json(['message' => self::NOT_READY_MESSAGE], 503);
        }

        $tenant = app('currentTenant');
        $connection = FacebookConnection::first();

        abort_if(! $connection, 404);

        try {
            $pages = $fb->getManagedPages($connection->user_access_token);
        } catch (FacebookGraphException $e) {
            $this->handleGraphFailure($e, $tenant->id, $connection->id);

            return response()->json(['message' => 'Facebook সংযোগ যাচাই ব্যর্থ হয়েছে — আবার কানেক্ট করুন।'], 422);
        } catch (ConnectionException $e) {
            Log::error('Facebook: connection failure while verifying page ownership (mobile).', ['tenant_id' => $tenant->id]);

            return response()->json(['message' => 'Facebook-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।'], 422);
        }

        // Never trust the submitted page_id on its own — only accept it if
        // it's actually present in a fresh /me/accounts response for this
        // tenant's authorized Facebook account, same as the web controller.
        $match = collect($pages)->firstWhere('id', $pageId);

        if (! $match) {
            return response()->json(['message' => 'এই Page-টি আপনার Facebook অ্যাকাউন্টে পাওয়া যায়নি।'], 422);
        }

        $claimedByAnotherTenant = FacebookPage::withoutGlobalScopes()
            ->where('page_id', $match['id'])
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($claimedByAnotherTenant) {
            return response()->json(['message' => 'এই Facebook Page ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।'], 422);
        }

        try {
            $page = FacebookPage::withoutGlobalScopes()->updateOrCreate(
                ['page_id' => $match['id']],
                [
                    'tenant_id' => $tenant->id,
                    'facebook_connection_id' => $connection->id,
                    'page_name' => $match['name'] ?? null,
                    'page_access_token' => $match['access_token'],
                    'is_active' => true,
                    'disconnected_at' => null,
                ]
            );
        } catch (QueryException $e) {
            // Race: another tenant claimed this exact page_id between the
            // check above and this write — UNIQUE(page_id) is the real
            // guarantee, same pattern as the web controller.
            if ($e->getCode() === '23000') {
                return response()->json(['message' => 'এই Facebook Page ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।'], 422);
            }

            throw $e;
        }

        try {
            $subscribed = $fb->subscribePageToWebhook($page->page_id, $match['access_token']);
        } catch (FacebookGraphException $e) {
            Log::error('Facebook: page webhook subscription failed (mobile).', [
                'tenant_id' => $tenant->id,
                'page_id' => $page->page_id,
                'error' => $e->errorPayload(),
            ]);
            $subscribed = false;
        } catch (ConnectionException $e) {
            Log::error('Facebook: connection failure during page webhook subscription (mobile).', [
                'tenant_id' => $tenant->id,
                'page_id' => $page->page_id,
            ]);
            $subscribed = false;
        }

        $page->update([
            'status' => $subscribed ? 'active' : 'subscription_failed',
            'subscribed_at' => $subscribed ? now() : null,
        ]);

        return response()->json([
            'ok' => $subscribed,
            'page' => $this->presentPage($page->fresh()),
            'message' => $subscribed
                ? 'Facebook Page কানেক্ট ও সাবস্ক্রাইব হয়েছে।'
                : 'Page কানেক্ট হয়েছে কিন্তু ওয়েবহুক সাবস্ক্রিপশন ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
        ]);
    }

    /** Mirrors Tenant\FacebookConnectController::disconnect() exactly. Not implicit route-model binding — see that method's docblock for why (SubstituteBindings runs before bind.tenant.token). */
    public function disconnect(int $page, FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return response()->json(['message' => self::NOT_READY_MESSAGE], 503);
        }

        $page = FacebookPage::findOrFail($page);

        if ($page->page_access_token) {
            try {
                $fb->unsubscribePageFromWebhook($page->page_id, $page->page_access_token);
            } catch (\Throwable $e) {
                // Deliberately not logging $e->getMessage() — see the web
                // controller's identical caution (the URL can carry the
                // Page Access Token in its query string).
                Log::warning('Facebook: unsubscribe on disconnect failed (mobile, disconnecting locally anyway).', [
                    'page_id' => $page->page_id,
                    'exception' => get_class($e),
                ]);
            }
        }

        $page->update([
            'is_active' => false,
            'page_access_token' => null,
            'disconnected_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    protected function presentPage(FacebookPage $page): array
    {
        return [
            'id' => $page->id,
            'page_id' => $page->page_id,
            'page_name' => $page->page_name,
            'status' => $page->status,
            'is_active' => (bool) $page->is_active,
        ];
    }

    protected function handleGraphFailure(FacebookGraphException $e, int $tenantId, int $connectionId): void
    {
        Log::error('Facebook: Graph API call failed (mobile).', [
            'tenant_id' => $tenantId,
            'error' => $e->errorPayload(),
        ]);

        if ($e->isInvalidToken()) {
            FacebookPage::where('facebook_connection_id', $connectionId)->update(['status' => 'needs_reconnect']);
        }
    }
}
