<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10 — converts a customer's voice message into text via OpenAI's
 * separate /audio/transcriptions endpoint (config('ai.transcription_model'),
 * default whisper-1) — a genuinely different API shape (multipart file
 * upload, not a JSON chat call) from AiProviderInterface::chat(), so this
 * is deliberately its own small class rather than forced into that
 * interface. Once transcribed, the resulting text is used exactly like a
 * customer-typed message for everything downstream (product matching,
 * business knowledge, style examples, generateReply(), ...) — no separate
 * "voice" content shape anywhere else in the pipeline, unlike Phase 9's
 * image handling, which genuinely needs a distinct multimodal content
 * part. See App\Jobs\ProcessAiAgentMessage::transcribeAndPersist() for
 * how the caller uses this and why the result is written back onto the
 * message row.
 *
 * Never throws — every failure mode (missing config, network error,
 * OpenAI error response, empty transcript) is caught here and degrades to
 * null, mirroring OpenAiProvider's posture, so a caller can never crash
 * from it and — critically — never charges credit for a failed attempt
 * (the caller only calls AiCreditService::recordTranscriptionUsage() when
 * this returns non-null).
 */
class AiAudioTranscriptionService
{
    /** @return array{text: string, durationSeconds: float}|null */
    public function transcribe(string $audioBytes, string $mimeType): ?array
    {
        $apiKey = config('ai.openai_api_key');

        if (! $apiKey) {
            Log::warning('AI transcription: OPENAI_API_KEY is not configured — cannot transcribe.');

            return null;
        }

        $model = (string) config('ai.transcription_model', 'whisper-1');
        $filename = 'audio.'.$this->guessExtension($mimeType);

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('ai.timeout_seconds', 20))
                ->attach('file', $audioBytes, $filename)
                ->post(rtrim(config('ai.openai_base_url'), '/').'/audio/transcriptions', [
                    'model' => $model,
                    // verbose_json is the only response_format that also
                    // returns 'duration' — needed to price the call per
                    // minute (see AiCreditService::recordTranscriptionUsage()).
                    'response_format' => 'verbose_json',
                ]);
        } catch (\Throwable $e) {
            // Same "never log the raw exception message" caution
            // OpenAiProvider applies — a transport exception can echo
            // request details.
            Log::warning('AI transcription: request failed at the transport level.', [
                'exception' => get_class($e),
            ]);

            return null;
        }

        if ($response->failed()) {
            $context = [
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
                'error_code' => $response->json('error.code'),
            ];

            if ($response->status() !== 401) {
                $context['error_message'] = $response->json('error.message');
            }

            Log::warning('AI transcription: API returned an error response.', $context);

            return null;
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return [
            'text' => trim($text),
            'durationSeconds' => (float) ($response->json('duration') ?? 0),
        ];
    }

    /**
     * WhatsApp voice notes and Messenger audio clips are both almost
     * always Ogg/Opus in practice — that's the shared default. Anything
     * OpenAI's transcription endpoint documents support gets its own
     * exact mapping; unrecognized falls back to ogg rather than guessing
     * wrong in a way that would make the upload fail outright.
     */
    protected function guessExtension(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/webm' => 'webm',
            default => 'ogg',
        };
    }
}
