<?php

namespace Tests\Unit\Services\RemoteSupport;

use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * iceServers() unit coverage — split from RemoteSupportControllerTest since
 * this is pure service logic around an external HTTP call (Cloudflare's
 * TURN credential-generation API), not an HTTP endpoint of our own.
 */
class RemoteSupportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('remote_support.stun_urls', ['stun:stun.l.google.com:19302']);
        Config::set('remote_support.turn_url', null);
        Config::set('remote_support.cloudflare_turn_key_id', null);
        Config::set('remote_support.cloudflare_turn_api_token', null);
    }

    public function test_returns_stun_only_when_no_turn_provider_configured(): void
    {
        $servers = (new RemoteSupportService)->iceServers();

        $this->assertSame([['urls' => 'stun:stun.l.google.com:19302']], $servers);
    }

    /** Regression guard: the generic static TURN config (non-Cloudflare) must still work unchanged. */
    public function test_falls_back_to_static_turn_config_when_cloudflare_is_not_configured(): void
    {
        Config::set('remote_support.turn_url', 'turn:example.com:3478');
        Config::set('remote_support.turn_username', 'u');
        Config::set('remote_support.turn_credential', 'p');

        $servers = (new RemoteSupportService)->iceServers();

        $this->assertCount(2, $servers);
        $this->assertSame([
            'urls' => 'turn:example.com:3478', 'username' => 'u', 'credential' => 'p',
        ], $servers[1]);
    }

    public function test_fetches_and_returns_cloudflare_turn_credentials_when_configured(): void
    {
        Config::set('remote_support.cloudflare_turn_key_id', 'key-123');
        Config::set('remote_support.cloudflare_turn_api_token', 'token-abc');

        Http::fake([
            'rtc.live.cloudflare.com/*' => Http::response([
                'iceServers' => [
                    'urls' => ['stun:stun.cloudflare.com:3478', 'turn:turn.cloudflare.com:3478?transport=udp'],
                    'username' => 'cf-user', 'credential' => 'cf-cred',
                ],
            ], 201),
        ]);

        $servers = (new RemoteSupportService)->iceServers();

        $this->assertCount(2, $servers);
        $this->assertSame('cf-user', $servers[1]['username']);
        $this->assertSame('cf-cred', $servers[1]['credential']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-abc')
            && str_contains($request->url(), 'key-123'));
    }

    /** Cloudflare is the preferred provider when both are configured — never silently prefers the static/legacy config. */
    public function test_cloudflare_takes_priority_over_static_turn_config_when_both_are_set(): void
    {
        Config::set('remote_support.turn_url', 'turn:legacy.example.com:3478');
        Config::set('remote_support.cloudflare_turn_key_id', 'key-123');
        Config::set('remote_support.cloudflare_turn_api_token', 'token-abc');

        Http::fake([
            'rtc.live.cloudflare.com/*' => Http::response([
                'iceServers' => ['urls' => ['turn:turn.cloudflare.com:3478'], 'username' => 'cf-user', 'credential' => 'cf-cred'],
            ], 201),
        ]);

        $servers = (new RemoteSupportService)->iceServers();

        $this->assertCount(2, $servers);
        $this->assertSame('cf-user', $servers[1]['username']);
    }

    /** A failed Cloudflare call must never break the session — degrade to STUN-only rather than throwing. */
    public function test_degrades_to_stun_only_when_cloudflare_call_fails(): void
    {
        Config::set('remote_support.cloudflare_turn_key_id', 'key-123');
        Config::set('remote_support.cloudflare_turn_api_token', 'bad-token');

        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $servers = (new RemoteSupportService)->iceServers();

        $this->assertCount(1, $servers);
        $this->assertSame('stun:stun.l.google.com:19302', $servers[0]['urls']);
    }

    /** A network-level exception (timeout, DNS failure) must also degrade gracefully, not bubble up into a 500 for the tenant/admin. */
    public function test_degrades_to_stun_only_when_cloudflare_call_throws(): void
    {
        Config::set('remote_support.cloudflare_turn_key_id', 'key-123');
        Config::set('remote_support.cloudflare_turn_api_token', 'token-abc');

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $servers = (new RemoteSupportService)->iceServers();

        $this->assertCount(1, $servers);
    }

    public function test_caches_cloudflare_credentials_across_calls(): void
    {
        Config::set('remote_support.cloudflare_turn_key_id', 'key-123');
        Config::set('remote_support.cloudflare_turn_api_token', 'token-abc');

        Http::fake([
            'rtc.live.cloudflare.com/*' => Http::response([
                'iceServers' => ['urls' => ['turn:turn.cloudflare.com:3478'], 'username' => 'cf-user', 'credential' => 'cf-cred'],
            ], 201),
        ]);

        $service = new RemoteSupportService;
        $service->iceServers();
        $service->iceServers();
        $service->iceServers();

        Http::assertSentCount(1);
    }

    /** A failed call must NOT be cached — the very next call should retry immediately rather than being stuck returning STUN-only for the rest of the cache window. */
    public function test_does_not_cache_a_failed_cloudflare_call(): void
    {
        Config::set('remote_support.cloudflare_turn_key_id', 'key-123');
        Config::set('remote_support.cloudflare_turn_api_token', 'token-abc');

        Http::fakeSequence()
            ->push(['error' => 'unavailable'], 500)
            ->push([
                'iceServers' => ['urls' => ['turn:turn.cloudflare.com:3478'], 'username' => 'cf-user', 'credential' => 'cf-cred'],
            ], 201);

        $service = new RemoteSupportService;
        $first = $service->iceServers();
        $second = $service->iceServers();

        $this->assertCount(1, $first);
        $this->assertCount(2, $second);
        Http::assertSentCount(2);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
