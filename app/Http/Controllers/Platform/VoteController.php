<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\VoteCampaign;
use App\Models\VoteEntry;
use App\Support\Platform\VoteRejected;
use App\Support\Platform\VoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Public voting: /vote/{brand-slug} (a brand's permanent, shareable vote
 * link) and /voting/{campaign-slug} (every category of a campaign).
 *
 * Request-level protections live here — per-IP throttle (route
 * middleware), a honeypot field, a minimum form-fill time, an optional
 * Cloudflare Turnstile check and a long-lived device cookie. The per-vote
 * rules (one per phone/category/period, device and IP caps, anomaly
 * flags) are in App\Support\Platform\VoteService.
 */
class VoteController extends Controller
{
    private const DEVICE_COOKIE = 'msbd_vd';

    public function show(Request $request, string $slug)
    {
        $brand = Brand::published()->where('slug', $slug)->with('category')->firstOrFail();

        $entries = $brand->voteEntries()->where('is_active', true)
            ->with('campaign', 'category')
            ->whereHas('campaign', fn ($q) => $q->visible())
            ->get()
            // Ended campaigns stay listed (as closed) for 30 days; a manually ended one has no ends_at, so use when it was ended.
            ->filter(fn ($e) => $e->campaign->phase() !== 'ended' || ($e->campaign->ends_at ?? $e->campaign->updated_at)?->gt(now()->subDays(30)))
            ->sortBy(fn ($e) => $e->campaign->isOpen() ? 0 : 1)
            ->values();

        return $this->withDeviceCookie($request, response()->view('central.platform.vote', [
            'brand' => $brand,
            'entries' => $entries,
            'formToken' => encrypt(now()->timestamp),
            'turnstileKey' => $this->turnstileEnabled() ? config('platform.turnstile.site_key') : null,
        ]));
    }

    public function campaign(Request $request, string $slug)
    {
        $campaign = VoteCampaign::visible()->where('slug', $slug)->with([
            'award',
            'categories.entries' => fn ($q) => $q->where('is_active', true)->whereHas('brand', fn ($b) => $b->published())
                ->orderByDesc('votes_count')->orderBy('id')->with('brand.category'),
        ])->firstOrFail();

        return view('central.platform.campaign', ['campaign' => $campaign]);
    }

    public function cast(Request $request, string $slug)
    {
        $brand = Brand::published()->where('slug', $slug)->firstOrFail();
        $data = $request->validate([
            'entry_id' => 'required|integer',
            'phone' => 'required|string|max:20',
        ]);

        $entry = VoteEntry::where('brand_id', $brand->id)->whereKey($data['entry_id'])->first();

        try {
            if (! $entry) {
                throw new VoteRejected('Voting for this brand is not open right now.');
            }
            $this->guardAgainstBots($request);
            app(VoteService::class)->cast($entry, $data['phone'], $request->cookie(self::DEVICE_COOKIE), $request->ip(), $request->userAgent());
        } catch (VoteRejected $e) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $e->getMessage()], 422)
                : back()->withErrors(['vote' => $e->getMessage()])->withInput($request->only('phone', 'entry_id'));
        }

        $entry->refresh()->load('campaign', 'category');
        $message = 'Thank you! Your vote for '.$brand->name.' in '.$entry->category->name.' has been counted.';

        return $request->expectsJson()
            ? response()->json([
                'ok' => true,
                'message' => $message,
                'votes' => $entry->campaign->show_counts ? $entry->votes_count : null,
                'rank' => $entry->campaign->show_counts ? $entry->rank() : null,
            ])
            : back()->with('voted', $message);
    }

    private function guardAgainstBots(Request $request): void
    {
        if (filled($request->input('website'))) {
            throw new VoteRejected('Your vote could not be verified. Please reload the page and try again.');
        }

        try {
            $issued = (int) decrypt((string) $request->input('form_token'));
        } catch (\Throwable) {
            $issued = 0;
        }
        $age = now()->timestamp - $issued;
        if ($issued === 0 || $age < 2 || $age > 6 * 3600) {
            throw new VoteRejected('Your vote could not be verified. Please reload the page and try again.');
        }

        if ($this->turnstileEnabled()) {
            try {
                $ok = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('platform.turnstile.secret'),
                    'response' => (string) $request->input('cf-turnstile-response'),
                    'remoteip' => $request->ip(),
                ])->json('success') === true;
            } catch (\Throwable $e) {
                Log::warning('Turnstile verification failed: '.$e->getMessage());
                $ok = false;
            }
            if (! $ok) {
                throw new VoteRejected('Please complete the security check and try again.');
            }
        }
    }

    private function turnstileEnabled(): bool
    {
        return filled(config('platform.turnstile.site_key')) && filled(config('platform.turnstile.secret'));
    }

    /** A long-lived random device id, so one browser can't cycle through many phone numbers unnoticed. */
    private function withDeviceCookie(Request $request, $response)
    {
        if (! $request->cookie(self::DEVICE_COOKIE)) {
            $response->withCookie(Cookie::make(self::DEVICE_COOKIE, Str::random(40), 60 * 24 * 365, '/', null, null, true, false, 'Lax'));
        }

        return $response;
    }
}
