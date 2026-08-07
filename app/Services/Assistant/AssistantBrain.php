<?php

namespace App\Services\Assistant;

use App\Models\AssistantMessage;
use Illuminate\Support\Facades\Http;

/**
 * The "brain" — sends the live business briefing + conversation memory
 * + the new message to Groq's free AI API, and returns a natural-language reply.
 *
 * Groq (console.groq.com) is used because it's completely free — no
 * credit card, generous rate limits, and OpenAI-compatible API format.
 */
class AssistantBrain
{
    public function __construct(protected BusinessBriefing $briefing) {}

    public function reply(string $chatId, string $userMessage): string
    {
        // 1. save the incoming message to memory
        AssistantMessage::create(['chat_id' => $chatId, 'role' => 'user', 'content' => $userMessage]);

        // 2. build conversation history (memory) for this chat
        $turns = (int) config('assistant.memory_turns', 20);
        $history = AssistantMessage::where('chat_id', $chatId)
            ->orderByDesc('id')->limit($turns)->get()->reverse()->values();

        // OpenAI-style chat format: system message first, then the turns
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt()]],
            $history->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all()
        );

        // 3. call Groq (free)
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('assistant.groq_api_key'),
            'Content-Type'  => 'application/json',
        ])->post('https://api.groq.com/openai/v1/chat/completions', [
            'model'    => config('assistant.groq_model'),
            'messages' => $messages,
            'max_tokens' => 1024,
        ]);

        if ($response->failed()) {
            return "⚠️ AI সার্ভিসে সংযোগ করা যায়নি। একটু পর আবার চেষ্টা করুন।\n\n(টেকনিক্যাল: " . $response->status() . ' — ' . $response->json('error.message') . ')';
        }

        $reply = $response->json('choices.0.message.content') ?? 'দুঃখিত, উত্তর তৈরি করা যায়নি।';

        // 4. save the assistant's reply to memory too
        AssistantMessage::create(['chat_id' => $chatId, 'role' => 'assistant', 'content' => $reply]);

        return $reply;
    }

    protected function systemPrompt(): string
    {
        $briefing = $this->briefing->generate();

        return <<<PROMPT
তুমি Bappi-র ব্যক্তিগত বিজনেস অ্যাসিস্ট্যান্ট — MetaSoft BD-র মালিকের জন্য। তুমি Telegram-এ কথা বলছ, শুধু তার সাথেই।

তুমি MetaSoft BD-র ShopSaaS প্ল্যাটফর্ম চালাতে সাহায্য করো — এটা বাংলাদেশি ব্যবসায়ীদের জন্য একটা মাল্টি-টেনেন্ট ইকমার্স ও বিজনেস অটোমেশন সিস্টেম (ওয়েবসাইট, POS, ইনভেন্টরি, কুরিয়ার, ফ্রড চেকার, অ্যাফিলিয়েট প্রোগ্রাম, এজেন্সি সার্ভিস ক্লায়েন্ট ম্যানেজমেন্ট)।

নিচে প্ল্যাটফর্মের এখনকার লাইভ অবস্থা দেওয়া হলো — এই সংখ্যাগুলো ব্যবহার করেই উত্তর দাও, নিজে থেকে সংখ্যা বানিও না:

$briefing

নিয়মাবলী:
- সংক্ষিপ্ত, সরাসরি, বন্ধুর মতো বাংলায় কথা বলো — ফরমাল রিপোর্ট না
- সংখ্যা জিজ্ঞেস করলে উপরের ডেটা থেকে সরাসরি উত্তর দাও
- ব্যবসায়িক পরামর্শ চাইলে (মার্কেটিং, প্রাইসিং, গ্রোথ, কাস্টমার রিটেনশন ইত্যাদি) নিজের জ্ঞান দিয়ে বাস্তবসম্মত পরামর্শ দাও
- আগের কথোপকথন মনে রাখো এবং প্রাসঙ্গিক হলে রেফারেন্স করো
- কোনো একশন (যেমন টেনেন্ট সাসপেন্ড করা, পেমেন্ট মার্ক করা) সরাসরি করতে পারো না — শুধু বলে দিতে পারো কোথায় গিয়ে করতে হবে (যেমন "সুপার অ্যাডমিন → টেনেন্ট → ওই টেনেন্টের প্রোফাইলে গিয়ে")
- অনিশ্চিত হলে সৎভাবে বলো যে জানো না, বানিয়ে বলো না
- Telegram Markdown ব্যবহার করতে পারো (bold করতে *এভাবে*)
PROMPT;
    }
}
