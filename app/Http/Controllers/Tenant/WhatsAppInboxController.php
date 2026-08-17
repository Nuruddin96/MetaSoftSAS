<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\AI\AiConversationStyleService;
use App\Services\AI\AiHandoffService;
use App\Services\ImageOptimizer;
use App\Services\Inbox\UnifiedInboxService;
use App\Services\WhatsApp\WhatsAppMediaService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Http\Request;

/**
 * WhatsApp's own conversation thread — a channel-native controller, not a
 * "generic channel" abstraction. Mirrors MessengerInboxController's proven
 * shape (show/reply/updateStatus/updates) wherever the concept genuinely
 * matches, using WhatsAppMessage/WhatsAppSendService directly rather than
 * anything Messenger-owned. Runs under the normal tenant panel middleware
 * stack, same as MessengerInboxController — every query here is
 * auto-scoped by BelongsToTenant, no withoutGlobalScopes() anywhere.
 */
class WhatsAppInboxController extends Controller
{
    /**
     * ?panel=1 (set by the inbox shell's row-click fetch-swap, see
     * resources/views/tenant/inbox/_shell_scripts.blade.php) returns ONLY
     * the center+right conversation partial — no layout, no list pane — so
     * the shell can inject it into an already-loaded page without a full
     * reload. Absent (a normal link click, direct link, bookmark, or
     * shared URL), this renders the complete page exactly as before this
     * redesign: same route, same controller action, genuinely the same
     * page at that URL either way — the only difference is how much of it
     * comes back.
     */
    public function show(Request $request, string $waId, UnifiedInboxService $inbox, AiHandoffService $handoff)
    {
        $messages = WhatsAppMessage::where('wa_id', $waId)
            ->orderBy('created_at')->get();

        abort_if($messages->isEmpty(), 404);

        // Same "latest row might be outbound and never carries
        // customer_name" reasoning as MessengerInboxController::show().
        $customer = $messages->last();
        $resolvedName = $messages->pluck('customer_name')->filter()->last();

        if ($resolvedName) {
            $customer->customer_name = $resolvedName;
        }

        // wa_id IS a phone number, so it can be matched against orders/
        // customers by phone without any schema change — but its digits-only
        // international format ("8801700000000") commonly differs from how
        // a phone got typed into an order ("01700000000"), so both forms are
        // tried. Best-effort: a genuine format mismatch beyond the 880/0
        // prefix (spacing, a different number entirely) will still miss —
        // "no linked order shown" must never be read as "definitely none."
        $phoneCandidates = $this->phoneCandidates($waId);

        $data = [
            'waId' => $waId,
            'messages' => $messages,
            'customer' => $customer,
            'connected' => WhatsAppPhoneNumber::where('is_active', 1)->where('status', 'active')->exists(),
            'linkedOrder' => Order::whereIn('customer_phone', $phoneCandidates)->latest()->first(),
            'matchedCustomer' => Customer::whereIn('phone', $phoneCandidates)->first(),
            'handoffActive' => $handoff->isActive(app('currentTenant')->id, 'whatsapp', $waId),
        ];

        if ($request->query('panel') === '1') {
            return view('tenant.whatsapp._conversation', $data);
        }

        // Unfiltered, all-channels list — the left pane shown alongside an
        // already-open conversation always shows the full inbox regardless
        // of whatever filter/search was active on tenant.inbox when this
        // conversation was opened from there (search/filter state isn't
        // carried across navigation in this pass — see _list.blade.php's
        // docblock for the same known limitation).
        $list = $inbox->paginate(null, 20);

        return view('tenant.whatsapp.show', $data + [
            'listConversations' => $list['conversations'],
            'listNextCursor' => $list['nextCursor'],
            'listHasMore' => $list['hasMore'],
        ]);
    }

    /**
     * @return list<string>
     */
    protected function phoneCandidates(string $rawId): array
    {
        $digits = preg_replace('/\D/', '', $rawId) ?? '';
        $candidates = [$digits];

        if (str_starts_with($digits, '880') && strlen($digits) > 10) {
            $candidates[] = '0'.substr($digits, 3); // 8801700000000 -> 01700000000
        } elseif (str_starts_with($digits, '0')) {
            $candidates[] = '880'.substr($digits, 1); // 01700000000 -> 8801700000000
        }

        return array_values(array_unique($candidates));
    }

    public function reply(Request $request, string $waId, WhatsAppSendService $service, AiConversationStyleService $style)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
            'image' => 'nullable|image|max:8192',
            'audio' => 'nullable|file|mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp3,audio/mp4,audio/wav,audio/x-m4a,audio/aac|max:8192',
        ]);

        $message = $data['message'] ?? null;

        if (! $message && ! $request->hasFile('image') && ! $request->hasFile('audio')) {
            return back()->with('error', 'মেসেজ, ছবি অথবা ভয়েস — অন্তত একটি দিন।');
        }

        $tenant = app('currentTenant');

        if ($message) {
            // sentBy defaults to 'human' — a genuine staff reply, exactly
            // like MessengerInboxController::reply() records. Distinguishes
            // it from ProcessWhatsAppAiAgentMessage's AI-generated replies,
            // which is what lets AiConversationStyleService::
            // whatsappStyleExamples() learn tone only from real human
            // replies — see chunk37.sql's docblock.
            $result = $service->sendText($tenant, $waId, $message);

            if (! $result->successful) {
                return back()->with('error', 'মেসেজ পাঠানো যায়নি: '.($result->errorMessage ?? 'WhatsApp API error'));
            }

            // A genuine human reply — possibly a correction of something
            // the AI got wrong — is exactly the high-value signal that
            // should shape the very next AI reply, not sit unused for up to
            // config('ai.style_cache_minutes'). See
            // AiConversationStyleService::forgetWhatsAppStyleCache()'s docblock.
            $style->forgetWhatsAppStyleCache($tenant->id);
        }

        if ($request->hasFile('image')) {
            // Same "our own durable URL, not a direct upload" convention
            // MessengerInboxController::reply() uses — WhatsAppSendService::
            // sendMedia() takes a link Meta can fetch, not a file body.
            $path = app(ImageOptimizer::class)->storeOptimized(
                $request->file('image'), 'public', 'whatsapp/'.$tenant->id.'/outgoing'
            );
            $url = asset('storage/'.$path);

            $result = $service->sendMedia($tenant, $waId, 'image', $url);

            if (! $result->successful) {
                return back()->with('error', 'ছবি পাঠানো যায়নি: '.($result->errorMessage ?? 'WhatsApp API error'));
            }
        }

        if ($request->hasFile('audio')) {
            // Recorded (MediaRecorder, browser) and uploaded (existing
            // file) voice notes both arrive here identically, as a normal
            // multipart file — see resources/views/tenant/whatsapp/
            // _conversation.blade.php's composer.
            $path = $request->file('audio')->store('whatsapp/'.$tenant->id.'/outgoing', 'public');
            $url = asset('storage/'.$path);

            $result = $service->sendMedia($tenant, $waId, 'audio', $url);

            if (! $result->successful) {
                return back()->with('error', 'ভয়েস মেসেজ পাঠানো যায়নি: '.($result->errorMessage ?? 'WhatsApp API error'));
            }
        }

        // WhatsAppSendService already sets status='contacted' on the NEW
        // outbound row it creates (Phase 3) — this separately clears the
        // unread/"new" badge on the EXISTING inbound rows of this
        // conversation, same transition MessengerInboxController::reply()
        // applies for its own thread.
        WhatsAppMessage::where('wa_id', $waId)->where('status', 'new')->update(['status' => 'contacted']);

        return back()->with('success', 'পাঠানো হয়েছে।');
    }

    public function updateStatus(Request $request, string $waId)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,converted,ignored']);

        WhatsAppMessage::where('wa_id', $waId)->update(['status' => $data['status']]);

        return back()->with('success', 'স্ট্যাটাস আপডেট হয়েছে।');
    }

    /**
     * Phase 13 — explicit staff action only; see AiHandoffService::resolve()'s
     * docblock for why a human panel reply never implicitly does this.
     */
    public function resumeAi(string $waId, AiHandoffService $handoff)
    {
        $handoff->resolve(app('currentTenant')->id, 'whatsapp', $waId, auth()->id());

        return back()->with('success', 'এই কনভারসেশনের জন্য AI Agent আবার চালু হয়েছে।');
    }

    /** Polling endpoint for the thread view — same "no confirmed queue worker on shared hosting" reasoning as MessengerInboxController::updates(), plain request/response, not WebSockets. */
    public function updates(Request $request)
    {
        $afterId = (int) $request->query('after_id', 0);
        $waId = $request->query('wa_id');

        $newMessages = WhatsAppMessage::where('id', '>', $afterId)->orderBy('id')->get();

        $response = [
            'latest_id' => max($afterId, (int) WhatsAppMessage::max('id')),
        ];

        if ($waId) {
            $response['messages'] = $newMessages->where('wa_id', $waId)->values()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'message_type' => $m->message_type,
                    'message_text' => $m->message_text,
                    'attachment_url' => $m->attachment_url,
                    // Live-proxied inbound media URL (WhatsAppMediaService via
                    // media()), same fields the initial server-rendered thread
                    // uses via WhatsAppMessage::inboundMediaId() — keeps the
                    // JS poller's rendering in parity with _attachment.blade.php
                    // instead of only ever showing the "not downloaded" text
                    // for messages that arrive after the page first loaded.
                    'inbound_media_url' => $m->inboundMediaId() ? route('tenant.whatsapp.media', $m->id) : null,
                    'attachment_type' => $m->attachment_type,
                    'attachment_name' => $m->attachment_name,
                    'delivery_status' => $m->delivery_status,
                    'time_label' => optional($m->created_at)->format('d M, h:i A'),
                ])->values();
        }

        return response()->json($response);
    }

    /**
     * Streams an inbound media attachment through the Cloud API on demand
     * (WhatsAppMediaService) — see that class's docblock for why this is a
     * live proxy rather than a stored copy. Deliberately NOT a type-hinted
     * {message} route parameter (no implicit model binding) — same
     * SubstituteBindings-runs-before-resolve.tenant reasoning documented on
     * WhatsAppConnectController::disconnect(): resolving inside the method
     * body, after every middleware (including 'feature:whatsapp' and
     * resolve.tenant) has run, is what makes WhatsAppMessage's
     * BelongsToTenant scope genuinely apply, so a foreign tenant's message
     * id correctly 404s instead of leaking another tenant's photo.
     */
    public function media(int $id, WhatsAppMediaService $service)
    {
        $message = WhatsAppMessage::with('phoneNumber.businessAccount')->findOrFail($id);

        $mediaId = $message->inboundMediaId();
        $token = $message->phoneNumber?->businessAccount?->user_access_token;

        abort_if(! $mediaId || ! $token, 404);

        $result = $service->fetch($mediaId, $token);

        abort_if(! $result, 404);

        return response($result['body'], 200)
            ->header('Content-Type', $result['mimeType'])
            ->header('Cache-Control', 'private, max-age=300');
    }
}
