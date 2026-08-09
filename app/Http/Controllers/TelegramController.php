<?php

namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Services\Assistant\AssistantBrain;
use App\Services\Assistant\BusinessBriefing;
use App\Services\Assistant\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    /** Telegram calls this URL for every incoming message. */
    public function webhook(Request $request, string $secret, TelegramService $telegram, AssistantBrain $brain, BusinessBriefing $briefing)
    {
        if (! hash_equals(config('assistant.webhook_secret'), $secret)) {
            abort(403);
        }

        $message = $request->input('message');
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if (! $chatId || $text === '') {
            return response()->json(['ok' => true]);
        }

        // Only respond to the owner's own Telegram account — this is a personal assistant.
        if ($chatId !== (string) config('assistant.telegram_chat_id')) {
            $telegram->sendMessage($chatId, 'এই বটটি ব্যক্তিগত ব্যবহারের জন্য — এখানে সাড়া দেওয়া হয় না।');

            return response()->json(['ok' => true]);
        }

        // Built-in quick commands (no AI call needed, instant + free)
        if ($text === '/start') {
            $telegram->sendMessage($chatId, "স্বাগতম! আমি আপনার MetaSoft BD অ্যাসিস্ট্যান্ট।\n\nযেকোনো প্রশ্ন করুন — টেনেন্ট, পেমেন্ট, ক্লায়েন্ট, অ্যাফিলিয়েট নিয়ে, অথবা ব্যবসার পরামর্শ চান।\n\n/status — এখনকার অবস্থা\n/clear — পুরনো কথোপকথন মুছে নতুন শুরু করুন");

            return response()->json(['ok' => true]);
        }

        if ($text === '/status') {
            $telegram->sendMessage($chatId, $briefing->generate());

            return response()->json(['ok' => true]);
        }

        if ($text === '/clear') {
            AssistantMessage::where('chat_id', $chatId)->delete();
            $telegram->sendMessage($chatId, '✅ মেমরি মুছে ফেলা হয়েছে, নতুন করে শুরু করুন।');

            return response()->json(['ok' => true]);
        }

        // Everything else goes to the AI
        $telegram->sendTyping($chatId);
        $reply = $brain->reply($chatId, $text);
        $telegram->sendMessage($chatId, $reply);

        return response()->json(['ok' => true]);
    }

    /** One-time setup route: registers the webhook URL with Telegram. */
    public function setup(Request $request, TelegramService $telegram)
    {
        abort_unless($request->query('key') === config('assistant.webhook_secret'), 403);

        $webhookUrl = url('/telegram/webhook/'.config('assistant.webhook_secret'));
        $result = $telegram->setWebhook($webhookUrl);

        return response()->json([
            'webhook_url' => $webhookUrl,
            'telegram_response' => $result,
        ]);
    }

    public function status(Request $request, TelegramService $telegram)
    {
        abort_unless($request->query('key') === config('assistant.webhook_secret'), 403);

        return response()->json($telegram->getWebhookInfo());
    }
}
