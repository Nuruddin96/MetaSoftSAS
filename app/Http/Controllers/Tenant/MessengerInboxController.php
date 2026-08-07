<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
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
            'connected'     => MessengerSetting::exists(),
        ]);
    }

    public function show(string $psid)
    {
        $messages = MessengerMessage::where('sender_psid', $psid)
            ->orderBy('created_at')->get();

        abort_if($messages->isEmpty(), 404);

        return view('tenant.messenger.show', [
            'psid'     => $psid,
            'messages' => $messages,
            'customer' => $messages->last(),
        ]);
    }

    public function reply(Request $request, string $psid, MessengerApi $api)
    {
        $data = $request->validate(['message' => 'required|string|max:1000']);

        $setting = MessengerSetting::where('is_active', 1)->first();

        if (! $setting) {
            return back()->with('error', 'মেসেঞ্জার পেজ কানেক্ট করা নেই।');
        }

        try {
            $api->sendMessage($psid, $data['message'], $setting->page_access_token);
        } catch (\Throwable $e) {
            return back()->with('error', 'মেসেজ পাঠানো যায়নি: ' . $e->getMessage());
        }

        MessengerMessage::create([
            'sender_psid'   => $psid,
            'message_text'  => $data['message'],
            'direction'     => 'out',
            'status'        => 'contacted',
        ]);

        // mark the conversation as contacted
        MessengerMessage::where('sender_psid', $psid)->where('status', 'new')->update(['status' => 'contacted']);

        return back()->with('success', 'রিপ্লাই পাঠানো হয়েছে।');
    }

    public function updateStatus(Request $request, string $psid)
    {
        $data = $request->validate(['status' => 'required|in:new,contacted,converted,ignored']);

        MessengerMessage::where('sender_psid', $psid)->update(['status' => $data['status']]);

        return back()->with('success', 'স্ট্যাটাস আপডেট হয়েছে।');
    }
}
