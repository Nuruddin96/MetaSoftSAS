<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FacebookPage;
use App\Models\MessengerCustomer;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\Order;
use App\Services\AI\AiConversationStyleService;
use App\Services\AI\AiHandoffService;
use App\Services\ImageOptimizer;
use App\Services\Inbox\UnifiedConversation;
use App\Services\Inbox\UnifiedInboxService;
use App\Services\Messenger\MessengerApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Mirrors Tenant\MessengerInboxController's real capability — list (via the
 * same UnifiedInboxService the web unified inbox uses), show (same
 * messages/customer-identity/linkedOrder/matchedCustomer/handoff
 * resolution), reply (text + image + audio, same rehosted-URL convention
 * as the web panel), updateStatus, resumeAi. Reused logic, not
 * reimplemented business rules — same "documented duplication over
 * refactor risk" convention CourierDispatchService/OrderCreationService
 * already established for this API surface.
 */
class MessengerController extends Controller
{
    public function index(Request $request, UnifiedInboxService $inbox)
    {
        // UnifiedInboxService builds each UnifiedConversation's show_url via
        // route('tenant.messenger.show', ...)/('tenant.whatsapp.show', ...),
        // which need a `tenant_slug` URL default — normally set by the
        // web-only ResolveTenant middleware (URL::defaults(['tenant_slug' =>
        // $tenant->subdomain])), which this Sanctum-token mobile route never
        // passes through. Set it locally here, scoped to this request only,
        // rather than touching ResolveTenant/UnifiedInboxService themselves
        // (shared, working web code) — confirmed necessary by a real
        // UrlGenerationException in this endpoint's own tests, not a guess.
        URL::defaults(['tenant_slug' => app('currentTenant')->subdomain]);

        $search = trim((string) $request->query('q'));
        $result = $inbox->paginate(
            $request->query('cursor'),
            20,
            'messenger',
            $search === '' ? null : $search,
            $request->boolean('unread'),
        );

        return response()->json([
            'data' => $result['conversations']->map(fn (UnifiedConversation $c) => [
                'psid' => $c->externalCustomerId,
                'customer_name' => $c->customerName,
                'avatar_url' => $c->avatarUrl,
                'message_text' => $c->lastMessageText,
                'attachment_type' => $c->lastMessageAttachmentType,
                'direction' => $c->lastMessageDirection,
                'status' => $c->status,
                'unread_count' => $c->unreadCount,
                'last_message_at' => $c->lastMessageAt->toIso8601String(),
            ])->values(),
            'next_cursor' => $result['nextCursor'],
            'has_more' => $result['hasMore'],
        ]);
    }

    public function show(Request $request, string $psid, AiHandoffService $handoff)
    {
        $messages = MessengerMessage::where('sender_psid', $psid)->orderBy('created_at')->get();

        abort_if($messages->isEmpty(), 404);

        $customer = $messages->last();
        $resolvedName = $messages->pluck('customer_name')->filter()->last();

        $identity = MessengerCustomer::tablesReady() ? MessengerCustomer::where('psid', $psid)->first() : null;
        $customerName = $identity?->display_name ?: ($resolvedName ?: $customer->customer_name);
        $avatarUrl = $identity?->profile_pic_url;

        $linkedOrder = Order::where('messenger_psid', $psid)->latest()->first();
        $matchedCustomer = $linkedOrder?->customer_phone
            ? Customer::where('phone', $linkedOrder->customer_phone)->first()
            : null;

        return response()->json([
            'psid' => $psid,
            'customer_name' => $customerName,
            'avatar_url' => $avatarUrl,
            'status' => $customer->status,
            'handoff_active' => $handoff->isActive(app('currentTenant')->id, 'messenger', $psid),
            'linked_order' => $linkedOrder ? ['id' => $linkedOrder->id, 'order_number' => $linkedOrder->order_number] : null,
            'matched_customer' => $matchedCustomer ? [
                'id' => $matchedCustomer->id,
                'name' => $matchedCustomer->name,
                'total_orders' => (int) $matchedCustomer->total_orders,
                'due_balance' => (float) $matchedCustomer->due_balance,
            ] : null,
            'messages' => $messages->map(fn (MessengerMessage $m) => [
                'id' => $m->id,
                'text' => $m->message_text,
                'attachment_url' => $m->attachment_url,
                'attachment_type' => $m->attachment_type,
                'direction' => $m->direction,
                'sent_by' => $m->sent_by,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Text + image + audio, same rehosted-URL convention as
     * Tenant\MessengerInboxController::reply() — Meta's Send API fetches a
     * URL, never a direct upload, so media is stored on our own disk first
     * and sent by its public asset() URL. All three fields are independently
     * nullable/combinable exactly like the web form; the client only ever
     * sends one per request in practice (matching the chat-composer UX),
     * but nothing here assumes that.
     *
     * Response shape is `{data: [...]}` (one entry per attachment/text
     * actually created) rather than a single object, since a request can
     * create more than one MessengerMessage row.
     */
    public function reply(Request $request, string $psid, MessengerApi $api, AiConversationStyleService $style)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
            'image' => 'nullable|image|max:8192',
            'audio' => 'nullable|file|mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp3,audio/mp4,audio/wav,audio/x-m4a,audio/aac|max:8192',
        ]);

        $message = $data['message'] ?? null;

        if (! $message && ! $request->hasFile('image') && ! $request->hasFile('audio')) {
            return response()->json(['message' => 'মেসেজ, ছবি অথবা ভয়েস — অন্তত একটি দিন।'], 422);
        }

        $token = $this->resolveReplyToken($psid);

        if ($token === false) {
            return response()->json(['message' => 'এই কনভারসেশনের Facebook Page বর্তমানে ডিসকানেক্টেড — রিপ্লাই পাঠানো যায়নি, Page-টি আবার কানেক্ট করুন।'], 422);
        }

        if (! $token) {
            return response()->json(['message' => 'মেসেঞ্জার পেজ কানেক্ট করা নেই।'], 422);
        }

        $created = [];

        if ($message) {
            try {
                $result = $api->sendMessage($psid, $message, $token);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'মেসেজ পাঠানো যায়নি: '.$e->getMessage()], 422);
            }

            if (isset($result['error'])) {
                return response()->json(['message' => 'মেসেজ পাঠানো যায়নি: '.($result['error']['message'] ?? 'Facebook API error')], 422);
            }

            $textAttrs = [
                'sender_psid' => $psid,
                'mid' => $result['message_id'] ?? null,
                'message_text' => $message,
                'direction' => 'out',
                'status' => 'contacted',
            ];

            if (MessengerMessage::sentByColumnReady()) {
                $textAttrs['sent_by'] = 'human';
            }

            $created[] = MessengerMessage::create($textAttrs);
            $style->forgetMessengerStyleCache(app('currentTenant')->id);
        }

        if ($request->hasFile('image')) {
            $path = app(ImageOptimizer::class)->storeOptimized(
                $request->file('image'), 'public', 'messenger/'.app('currentTenant')->id.'/outgoing'
            );
            $url = asset('storage/'.$path);

            try {
                $result = $api->sendAttachment($psid, $url, 'image', $token);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'ছবি পাঠানো যায়নি: '.$e->getMessage()], 422);
            }

            if (isset($result['error'])) {
                return response()->json(['message' => 'ছবি পাঠানো যায়নি: '.($result['error']['message'] ?? 'Facebook API error')], 422);
            }

            $attrs = [
                'sender_psid' => $psid,
                'mid' => $result['message_id'] ?? null,
                'attachment_url' => $url,
                'direction' => 'out',
                'status' => 'contacted',
            ];

            if (MessengerMessage::attachmentColumnsReady()) {
                $attrs['attachment_type'] = 'image';
            }

            if (MessengerMessage::sentByColumnReady()) {
                $attrs['sent_by'] = 'human';
            }

            $created[] = MessengerMessage::create($attrs);
        }

        if ($request->hasFile('audio')) {
            $path = $request->file('audio')->store('messenger/'.app('currentTenant')->id.'/outgoing', 'public');
            $url = asset('storage/'.$path);

            try {
                $result = $api->sendAttachment($psid, $url, 'audio', $token);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'ভয়েস মেসেজ পাঠানো যায়নি: '.$e->getMessage()], 422);
            }

            if (isset($result['error'])) {
                return response()->json(['message' => 'ভয়েস মেসেজ পাঠানো যায়নি: '.($result['error']['message'] ?? 'Facebook API error')], 422);
            }

            $attrs = [
                'sender_psid' => $psid,
                'mid' => $result['message_id'] ?? null,
                'attachment_url' => $url,
                'direction' => 'out',
                'status' => 'contacted',
            ];

            if (MessengerMessage::attachmentColumnsReady()) {
                $attrs['attachment_type'] = 'audio';
            }

            if (MessengerMessage::sentByColumnReady()) {
                $attrs['sent_by'] = 'human';
            }

            $created[] = MessengerMessage::create($attrs);
        }

        MessengerMessage::where('sender_psid', $psid)->where('status', 'new')->update(['status' => 'contacted']);

        return response()->json([
            'data' => collect($created)->map(fn (MessengerMessage $m) => [
                'id' => $m->id,
                'text' => $m->message_text,
                'attachment_url' => $m->attachment_url,
                'attachment_type' => $m->attachment_type,
                'direction' => $m->direction,
                'sent_by' => $m->sent_by ?? null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ], 201);
    }

    public function updateStatus(Request $request, string $psid)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,converted,ignored']);

        $updated = MessengerMessage::where('sender_psid', $psid)->update(['status' => $data['status']]);
        abort_if($updated === 0, 404);

        return response()->json(['ok' => true, 'status' => $data['status']]);
    }

    public function resumeAi(Request $request, string $psid, AiHandoffService $handoff)
    {
        $handoff->resolve(app('currentTenant')->id, 'messenger', $psid, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /** Identical to Tenant\MessengerInboxController::resolveReplyToken(). */
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
}
