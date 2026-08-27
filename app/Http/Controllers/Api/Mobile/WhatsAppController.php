<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\WhatsAppMessage;
use App\Services\AI\AiConversationStyleService;
use App\Services\AI\AiHandoffService;
use App\Services\Inbox\UnifiedConversation;
use App\Services\Inbox\UnifiedInboxService;
use App\Services\WhatsApp\WhatsAppMediaService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Mirrors Tenant\WhatsAppInboxController's real capability — same shape as
 * Api\Mobile\MessengerController (list via the real UnifiedInboxService,
 * show, reply, status, resume-ai). See MessengerController's docblock for
 * the shared "text-only reply" and "URL::defaults tenant_slug" reasoning,
 * both apply identically here.
 *
 * Unlike Messenger's reply(), WhatsAppSendService::sendText() already
 * creates+persists the outbound WhatsAppMessage row itself (returned on
 * WhatsAppSendResult::$message) — this controller does not create one
 * manually, matching the real service's own behavior exactly.
 */
class WhatsAppController extends Controller
{
    public function index(Request $request, UnifiedInboxService $inbox)
    {
        URL::defaults(['tenant_slug' => app('currentTenant')->subdomain]);

        $search = trim((string) $request->query('q'));
        $result = $inbox->paginate(
            $request->query('cursor'),
            20,
            'whatsapp',
            $search === '' ? null : $search,
            $request->boolean('unread'),
        );

        return response()->json([
            'data' => $result['conversations']->map(fn (UnifiedConversation $c) => [
                'wa_id' => $c->externalCustomerId,
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

    public function show(Request $request, string $waId, AiHandoffService $handoff)
    {
        $messages = WhatsAppMessage::where('wa_id', $waId)->orderBy('created_at')->get();

        abort_if($messages->isEmpty(), 404);

        $customer = $messages->last();
        $resolvedName = $messages->pluck('customer_name')->filter()->last();
        $customerName = $resolvedName ?: $customer->customer_name;

        $phoneCandidates = $this->phoneCandidates($waId);
        $linkedOrder = Order::whereIn('customer_phone', $phoneCandidates)->latest()->first();
        $matchedCustomer = Customer::whereIn('phone', $phoneCandidates)->first();

        return response()->json([
            'wa_id' => $waId,
            'customer_name' => $customerName,
            'status' => $customer->status,
            'handoff_active' => $handoff->isActive(app('currentTenant')->id, 'whatsapp', $waId),
            'linked_order' => $linkedOrder ? ['id' => $linkedOrder->id, 'order_number' => $linkedOrder->order_number] : null,
            'matched_customer' => $matchedCustomer ? [
                'id' => $matchedCustomer->id,
                'name' => $matchedCustomer->name,
                'total_orders' => (int) $matchedCustomer->total_orders,
                'due_balance' => (float) $matchedCustomer->due_balance,
            ] : null,
            'messages' => $messages->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id,
                'text' => $m->message_text,
                'attachment_url' => $m->attachment_url,
                // Live-proxied inbound media URL — same field/computation as
                // Tenant\WhatsAppInboxController's 'inbound_media_url'
                // (updates() and _attachment.blade.php), null unless this is
                // an inbound message with a resolvable Meta media id. Points
                // at media() below, which is itself Sanctum-authenticated
                // like every other route in this group — the client must
                // attach its bearer token when fetching this URL directly
                // (a plain <img>/browser request with no Authorization
                // header will 401).
                'inbound_media_url' => $m->inboundMediaId() ? url('/api/mobile/v1/whatsapp/media/'.$m->id) : null,
                'attachment_type' => $m->attachment_type,
                'attachment_name' => $m->attachment_name,
                'direction' => $m->direction,
                'sent_by' => $m->sent_by,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Streams an inbound media attachment through the Cloud API on demand —
     * exact mirror of Tenant\WhatsAppInboxController::media() (same
     * WhatsAppMediaService, same two-step Graph API proxy, same "no stored
     * copy" reasoning, see that service's docblock). Not a generic URL
     * proxy: [id] only ever resolves a Meta media id already recorded on
     * this tenant's own WhatsAppMessage row (via inboundMediaId(), which
     * itself only returns non-null for an inbound row with a real
     * attachment_type) — a caller cannot pass an arbitrary URL or fetch
     * another tenant's media, since BelongsToTenant's global scope applies
     * to the findOrFail() lookup the same way it does on every other
     * WhatsAppMessage query in this controller.
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

    public function reply(Request $request, string $waId, WhatsAppSendService $service, AiConversationStyleService $style)
    {
        $data = $request->validate(['message' => 'required|string|max:1000']);
        $tenant = app('currentTenant');

        $result = $service->sendText($tenant, $waId, $data['message']);

        if (! $result->successful) {
            return response()->json(['message' => 'মেসেজ পাঠানো যায়নি: '.($result->errorMessage ?? 'WhatsApp API error')], 422);
        }

        $style->forgetWhatsAppStyleCache($tenant->id);

        WhatsAppMessage::where('wa_id', $waId)->where('status', 'new')->update(['status' => 'contacted']);

        $message = $result->message;

        return response()->json([
            'id' => $message->id,
            'text' => $message->message_text,
            'direction' => $message->direction,
            'sent_by' => $message->sent_by ?? null,
            'created_at' => $message->created_at?->toIso8601String(),
        ], 201);
    }

    public function updateStatus(Request $request, string $waId)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,converted,ignored']);

        $updated = WhatsAppMessage::where('wa_id', $waId)->update(['status' => $data['status']]);
        abort_if($updated === 0, 404);

        return response()->json(['ok' => true, 'status' => $data['status']]);
    }

    public function resumeAi(Request $request, string $waId, AiHandoffService $handoff)
    {
        $handoff->resolve(app('currentTenant')->id, 'whatsapp', $waId, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /** Identical to Tenant\WhatsAppInboxController::phoneCandidates(). */
    protected function phoneCandidates(string $rawId): array
    {
        $digits = preg_replace('/\D/', '', $rawId) ?? '';
        $candidates = [$digits];

        if (str_starts_with($digits, '880') && strlen($digits) > 10) {
            $candidates[] = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $candidates[] = '880'.substr($digits, 1);
        }

        return array_values(array_unique($candidates));
    }
}
