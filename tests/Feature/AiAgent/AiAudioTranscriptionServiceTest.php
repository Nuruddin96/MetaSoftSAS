<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiAudioTranscriptionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiAudioTranscriptionService in isolation — the
 * Phase 10 OpenAI /audio/transcriptions wrapper. Mirrors OpenAiProvider's
 * own test posture: never a real network call, every failure mode
 * degrades to null rather than throwing.
 */
class AiAudioTranscriptionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.openai_api_key' => 'test-key']);
    }

    public function test_returns_the_transcribed_text_and_duration_on_success(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'দাম কত সেটা জানতে চাই', 'duration' => 12.5]),
        ]);

        $result = app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        $this->assertSame('দাম কত সেটা জানতে চাই', $result['text']);
        $this->assertSame(12.5, $result['durationSeconds']);
    }

    public function test_sends_the_configured_transcription_model(): void
    {
        config(['ai.transcription_model' => 'gpt-4o-mini-transcribe']);
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => 'ok', 'duration' => 1])]);

        app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        Http::assertSent(function ($request) {
            return str_contains((string) $request->body(), 'gpt-4o-mini-transcribe');
        });
    }

    public function test_returns_null_when_the_api_key_is_not_configured(): void
    {
        config(['ai.openai_api_key' => null]);
        Http::fake();

        $result = app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_returns_null_when_the_api_responds_with_an_error(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['error' => ['message' => 'bad file', 'type' => 'invalid_request_error']], 400)]);

        $result = app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        $this->assertNull($result);
    }

    public function test_returns_null_on_a_transport_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('simulated');
        });

        $result = app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        $this->assertNull($result);
    }

    public function test_returns_null_when_the_transcript_is_empty(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => '   ', 'duration' => 3])]);

        $result = app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        $this->assertNull($result);
    }

    public function test_defaults_duration_to_zero_when_the_api_omits_it(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => 'ok'])]);

        $result = app(AiAudioTranscriptionService::class)->transcribe('fake-audio-bytes', 'audio/ogg');

        $this->assertSame(0.0, $result['durationSeconds']);
    }
}
