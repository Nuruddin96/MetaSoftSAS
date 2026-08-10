<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\Order;
use App\Services\ImageOptimizer;
use App\Services\Messenger\MessengerApi;
use Illuminate\Http\Request;

class MessengerInboxController extends Controller
{
    public function index()
    {
        // group by sender_psid, show latest message per conversation
        $latestIds = MessengerMessage::selectRaw('MAX(id) as id')
            ->groupBy('sender_psid')
            ->pluck('id');

        $conversations = MessengerMessage::whereIn('id', $latestIds)
            ->orderByDesc('created_at')->paginate(20);

        return view('tenant.messenger.index', [
            'conversations' => $conversations,
            'connected' => MessengerSetting::exists()
                || (FacebookPage::tablesReady() && FacebookPage::where('is_active', 1)->exists()),
        ]);
    }

    public function show(string $psid)
    {
        $messages = MessengerMessage::where('sender_psid', $psid)
            ->orderBy('created_at')->get();

        abort_if($messages->isEmpty(), 404);

        return view('tenant.messenger.show', [
            'psid' => $psid,
            'messages' => $messages,
            'customer' => $messages->last(),
            'linkedOrder' => Order::where('messenger_psid', $psid)->latest()->first(),
        ]);
    }

    public function reply(Request $request, string $psid, MessengerApi $api)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
            'image' => 'nullable|image|max:8192',
        ]);

        // nullable rules don't force the key into validate()'s return value
        // when the input field is absent entirely (as opposed to present-
        // but-empty) — normalize once so every use below is a plain lookup.
        $message = $data['message'] ?? null;

        if (! $message && ! $request->hasFile('image')) {
            return back()->with('error', 'মেসেজ অথবা ছবি — অন্তত একটি দিন।');
        }

        $token = $this->resolveReplyToken($psid);

        if ($token === false) {
            // This conversation is tied to a specific connected Page that is
            // now disconnected/inactive — fail safely. Never fall back to a
            // different Page's token for the same conversation.
            return back()->with('error', 'এই কনভারসেশনের Facebook Page বর্তমানে ডিসকানেক্টেড — রিপ্লাই পাঠানো যায়নি, Page-টি আবার কানেক্ট করুন।');
        }

        if (! $token) {
            return back()->with('error', 'মেসেঞ্জার পেজ কানেক্ট করা নেই।');
        }

        if ($message) {
            try {
                $result = $api->sendMessage($psid, $message, $token);
            } catch (\Throwable $e) {
                return back()->with('error', 'মেসেজ পাঠানো যায়নি: '.$e->getMessage());
            }

            MessengerMessage::create([
                'sender_psid' => $psid,
                // Meta's Send API returns its own id for this message as
                // message_id. Recording it as our mid now means that when the
                // matching message_echoes webhook event arrives later, its
                // mid-based dedup check (see MessengerWebhookController::
                // handleEvent()) finds this row already exists and skips it —
                // so a panel reply is never inserted a second time as an echo.
                'mid' => $result['message_id'] ?? null,
                'message_text' => $message,
                'direction' => 'out',
                'status' => 'contacted',
            ]);
        }

        if ($request->hasFile('image')) {
            // Same "our own durable URL, not a direct upload" convention
            // MessengerWebhookController::rehostAttachment() uses for
            // inbound media — Meta's Send API takes a URL it can fetch, not
            // a file body, so the image has to be publicly reachable first.
            $path = app(ImageOptimizer::class)->storeOptimized(
                $request->file('image'), 'public', 'messenger/'.app('currentTenant')->id.'/outgoing'
            );
            $url = asset('storage/'.$path);

            try {
                $result = $api->sendAttachment($psid, $url, 'image', $token);
            } catch (\Throwable $e) {
                return back()->with('error', 'ছবি পাঠানো যায়নি: '.$e->getMessage());
            }

            MessengerMessage::create([
                'sender_psid' => $psid,
                'mid' => $result['message_id'] ?? null,
                'attachment_url' => $url,
                'attachment_type' => 'image',
                'direction' => 'out',
                'status' => 'contacted',
            ]);
        }

        // mark the conversation as contacted
        MessengerMessage::where('sender_psid', $psid)->where('status', 'new')->update(['status' => 'contacted']);

        return back()->with('success', 'পাঠানো হয়েছে।');
    }

    /**
     * Resolves the Page Access Token to reply with for one specific
     * conversation — never an arbitrary "first active" connected Page.
     * A conversation's Page is read from the most recent message on record
     * for this psid that carries a facebook_page_id (set by
     * MessengerWebhookController::resolvePageOwner() when the message
     * arrived) — never guessed.
     *
     * Returns:
     *  - string  the Page Access Token to send with.
     *  - false   the conversation IS tied to a specific Page, but that Page
     *            is disconnected/inactive/gone — caller must fail safely,
     *            never substitute a different Page's token.
     *  - null    no Page is on record for this conversation at all (a
     *            legacy messenger_settings-era conversation, or it predates
     *            the facebook_page_id column) — falls back to the single
     *            legacy connection, never to an unrelated OAuth-connected
     *            Page.
     *
     * FacebookPage::where('id', ...) is still tenant-scoped by
     * BelongsToTenant's global scope even though facebook_page_id already
     * only ever points at this tenant's own rows by construction — this is
     * the defense-in-depth layer that makes a cross-tenant token leak
     * structurally impossible here, not just "shouldn't happen."
     */
    protected function resolveReplyToken(string $psid): string|false|null
    {
        $facebookPageId = FacebookPage::tablesReady()
            ? MessengerMessage::where('sender_psid', $psid)
                ->whereNotNull('facebook_page_id')
                ->orderByDesc('id')
                ->value('facebook_page_id')
            : null;

        if ($facebookPageId) {
            $page = FacebookPage::where('id', $facebookPageId)
                ->where('is_active', 1)
                ->where('status', 'active')
                ->first();

            return $page ? $page->page_access_token : false;
        }

        return optional(MessengerSetting::where('is_active', 1)->first())->page_access_token;
    }

    public function updateStatus(Request $request, string $psid)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,converted,ignored']);

        MessengerMessage::where('sender_psid', $psid)->update(['status' => $data['status']]);

        return back()->with('success', 'স্ট্যাটাস আপডেট হয়েছে।');
    }

    /**
     * Polling endpoint for the "feels real-time" inbox — Hostinger shared
     * hosting has no confirmed queue worker/scheduler running, so this is
     * plain request/response polled from the browser rather than
     * WebSockets/Echo/Reverb. Runs under the normal tenant panel middleware
     * stack, so every query here is auto-scoped by BelongsToTenant exactly
     * like index()/show() — no manual tenant_id filtering needed, and no
     * way for one tenant's poll to see another tenant's messages.
     */
    public function updates(Request $request)
    {
        $afterId = (int) $request->query('after_id', 0);
        $psid = $request->query('psid');

        $newMessages = MessengerMessage::where('id', '>', $afterId)->orderBy('id')->get();

        $touchedPsids = $newMessages->pluck('sender_psid')->unique();

        $conversations = [];

        if ($touchedPsids->isNotEmpty()) {
            $latestIds = MessengerMessage::whereIn('sender_psid', $touchedPsids)
                ->selectRaw('MAX(id) as id')->groupBy('sender_psid')->pluck('id');

            $conversations = MessengerMessage::whereIn('id', $latestIds)->get()
                ->map(fn ($c) => [
                    'psid' => $c->sender_psid,
                    'customer_name' => $c->customer_name,
                    'message_text' => $c->message_text,
                    'has_attachment' => (bool) $c->attachment_url,
                    'attachment_type' => $c->attachment_type,
                    'direction' => $c->direction,
                    'status' => $c->status,
                    'time_label' => optional($c->created_at)->diffForHumans(),
                    'unread' => MessengerMessage::where('sender_psid', $c->sender_psid)
                        ->where('status', 'new')->where('direction', 'in')->count(),
                ])->values();
        }

        $response = [
            'latest_id' => max($afterId, (int) MessengerMessage::max('id')),
            'conversations' => $conversations,
            'unread_total' => MessengerMessage::where('status', 'new')->where('direction', 'in')->count(),
        ];

        if ($psid) {
            $response['messages'] = $newMessages->where('sender_psid', $psid)->values()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'message_text' => $m->message_text,
                    'attachment_url' => $m->attachment_url,
                    'attachment_type' => $m->attachment_type,
                    'attachment_name' => $m->attachment_name,
                    'time_label' => optional($m->created_at)->format('d M, h:i A'),
                ])->values();
        }

        return response()->json($response);
    }
}
