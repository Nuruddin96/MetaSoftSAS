<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\ImageOptimizer;
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
    public function show(string $waId)
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

        return view('tenant.whatsapp.show', [
            'waId' => $waId,
            'messages' => $messages,
            'customer' => $customer,
            'connected' => WhatsAppPhoneNumber::where('is_active', 1)->where('status', 'active')->exists(),
        ]);
    }

    public function reply(Request $request, string $waId, WhatsAppSendService $service)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
            'image' => 'nullable|image|max:8192',
        ]);

        $message = $data['message'] ?? null;

        if (! $message && ! $request->hasFile('image')) {
            return back()->with('error', 'মেসেজ অথবা ছবি — অন্তত একটি দিন।');
        }

        $tenant = app('currentTenant');

        if ($message) {
            $result = $service->sendText($tenant, $waId, $message);

            if (! $result->successful) {
                return back()->with('error', 'মেসেজ পাঠানো যায়নি: '.($result->errorMessage ?? 'WhatsApp API error'));
            }
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
                    'attachment_type' => $m->attachment_type,
                    'attachment_name' => $m->attachment_name,
                    'delivery_status' => $m->delivery_status,
                    'time_label' => optional($m->created_at)->format('d M, h:i A'),
                ])->values();
        }

        return response()->json($response);
    }
}
