<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\FacebookGraphException;
use App\Http\Controllers\Controller;
use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class FacebookConnectController extends Controller
{
    /** Shown instead of a raw SQL error if database/sql/chunk23.sql hasn't been (fully) imported yet. */
    protected const NOT_READY_MESSAGE = 'Facebook ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি — একটু পর আবার চেষ্টা করুন।';

    /** Tenant clicks "Connect Facebook" — mint state, redirect to Meta's OAuth dialog. */
    public function redirect(FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return redirect()->route('tenant.settings')->with('error', self::NOT_READY_MESSAGE);
        }

        $tenant = app('currentTenant');
        $user = auth('tenant')->user();

        $state = $fb->createState($tenant, $user);

        return redirect()->away($fb->authorizationUrl($state));
    }

    /** Page picker — always live-fetches /me/accounts, never trusts cached/session data. */
    public function pages(FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return redirect()->route('tenant.settings')->with('error', self::NOT_READY_MESSAGE);
        }

        $tenant = app('currentTenant');
        $connection = FacebookConnection::first();

        if (! $connection) {
            return redirect()->route('tenant.settings')->with('error', 'প্রথমে Facebook কানেক্ট করুন।');
        }

        try {
            $pages = $fb->getManagedPages($connection->user_access_token);
        } catch (FacebookGraphException $e) {
            $this->handleGraphFailure($e, $tenant->id, $connection->id);

            return redirect()->route('tenant.settings')->with(
                'error',
                $e->isInvalidToken()
                    ? 'Facebook সংযোগের মেয়াদ শেষ হয়েছে — আবার কানেক্ট করুন।'
                    : 'Facebook থেকে Page তালিকা আনা যায়নি, একটু পর আবার চেষ্টা করুন।'
            );
        } catch (ConnectionException $e) {
            Log::error('Facebook: connection failure while listing pages.', ['tenant_id' => $tenant->id]);

            return redirect()->route('tenant.settings')->with('error', 'Facebook-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।');
        }

        $connectedIds = FacebookPage::where('is_active', 1)->pluck('page_id')->all();

        return view('tenant.facebook.pages', [
            'pages' => $pages,
            'connectedIds' => $connectedIds,
        ]);
    }

    /** Tenant selects one Page from the picker. */
    public function connect(string $pageId, FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return redirect()->route('tenant.settings')->with('error', self::NOT_READY_MESSAGE);
        }

        $tenant = app('currentTenant');
        $connection = FacebookConnection::first();

        abort_if(! $connection, 404);

        try {
            $pages = $fb->getManagedPages($connection->user_access_token);
        } catch (FacebookGraphException $e) {
            $this->handleGraphFailure($e, $tenant->id, $connection->id);

            return redirect()->route('tenant.settings')->with('error', 'Facebook সংযোগ যাচাই ব্যর্থ হয়েছে — আবার কানেক্ট করুন।');
        } catch (ConnectionException $e) {
            Log::error('Facebook: connection failure while verifying page ownership.', ['tenant_id' => $tenant->id]);

            return redirect()->route('tenant.settings')->with('error', 'Facebook-এর সাথে সংযোগ করা যায়নি, একটু পর আবার চেষ্টা করুন।');
        }

        // Never trust the submitted page_id on its own — only accept it if
        // it's actually present in a fresh /me/accounts response for this
        // tenant's authorized Facebook account. The Page Access Token used
        // below also comes only from this response, never from the request.
        $match = collect($pages)->firstWhere('id', $pageId);

        if (! $match) {
            return back()->with('error', 'এই Page-টি আপনার Facebook অ্যাকাউন্টে পাওয়া যায়নি।');
        }

        $claimedByAnotherTenant = FacebookPage::withoutGlobalScopes()
            ->where('page_id', $match['id'])
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($claimedByAnotherTenant) {
            return redirect()->route('tenant.settings')
                ->with('error', 'এই Facebook Page ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।');
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
            // check above and this write. UNIQUE(page_id) is the real
            // guarantee — same pattern as SettingController::messenger().
            if ($e->getCode() === '23000') {
                return redirect()->route('tenant.settings')
                    ->with('error', 'এই Facebook Page ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে।');
            }

            throw $e;
        }

        try {
            $subscribed = $fb->subscribePageToWebhook($page->page_id, $match['access_token']);
        } catch (FacebookGraphException $e) {
            Log::error('Facebook: page webhook subscription failed.', [
                'tenant_id' => $tenant->id,
                'page_id' => $page->page_id,
                'error' => $e->errorPayload(),
            ]);
            $subscribed = false;
        } catch (ConnectionException $e) {
            Log::error('Facebook: connection failure during page webhook subscription.', [
                'tenant_id' => $tenant->id,
                'page_id' => $page->page_id,
            ]);
            $subscribed = false;
        }

        $page->update([
            'status' => $subscribed ? 'active' : 'subscription_failed',
            'subscribed_at' => $subscribed ? now() : null,
        ]);

        return redirect()->route('tenant.settings')->with(
            $subscribed ? 'success' : 'error',
            $subscribed
                ? 'Facebook Page কানেক্ট ও সাবস্ক্রাইব হয়েছে।'
                : 'Page কানেক্ট হয়েছে কিন্তু ওয়েবহুক সাবস্ক্রিপশন ব্যর্থ হয়েছে। আবার চেষ্টা করুন।'
        );
    }

    public function disconnect(int $page, FacebookOAuthService $fb)
    {
        if (! FacebookPage::tablesReady()) {
            return redirect()->route('tenant.settings')->with('error', self::NOT_READY_MESSAGE);
        }

        // Deliberately NOT an implicitly-bound `FacebookPage $page` route
        // parameter: SubstituteBindings (part of the framework's 'web'
        // middleware group, which wraps this whole route file) runs BEFORE
        // resolve.tenant in this app's route-group nesting, so an implicit
        // binding would resolve without the BelongsToTenant scope active
        // yet — silently allowing a foreign tenant's page to be resolved by
        // ID. Resolving explicitly here, inside the method body, runs after
        // resolve.tenant has bound currentTenant, so the scope genuinely
        // applies and a foreign tenant's page ID correctly 404s.
        $page = FacebookPage::findOrFail($page);

        if ($page->page_access_token) {
            try {
                $fb->unsubscribePageFromWebhook($page->page_id, $page->page_access_token);
            } catch (\Throwable $e) {
                // Deliberately not logging $e->getMessage() — a connection
                // failure's exception message can embed the full request
                // URI, and unsubscribePageFromWebhook() carries the Page
                // Access Token in that URL's query string.
                Log::warning('Facebook: unsubscribe on disconnect failed (disconnecting locally anyway).', [
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

        return back()->with('success', 'Page ডিসকানেক্ট করা হয়েছে।');
    }

    protected function handleGraphFailure(FacebookGraphException $e, int $tenantId, int $connectionId): void
    {
        Log::error('Facebook: Graph API call failed.', [
            'tenant_id' => $tenantId,
            'error' => $e->errorPayload(),
        ]);

        if ($e->isInvalidToken()) {
            FacebookPage::where('facebook_connection_id', $connectionId)
                ->update(['status' => 'needs_reconnect']);
        }
    }
}
