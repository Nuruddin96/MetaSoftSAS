<?php

return [
    // BotFather token, and your personal Telegram chat_id (only this id gets replies).
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'telegram_chat_id'   => env('TELEGRAM_CHAT_ID'),
    'webhook_secret'     => env('TELEGRAM_WEBHOOK_SECRET', 'change-this-secret'),

    // Groq — free AI API (no credit card needed). console.groq.com
    'groq_api_key' => env('GROQ_API_KEY'),
    'groq_model'   => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),

    // How many past messages (user+assistant combined) to keep as memory context.
    'memory_turns' => env('ASSISTANT_MEMORY_TURNS', 20),
];
