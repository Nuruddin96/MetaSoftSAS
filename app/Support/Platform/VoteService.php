<?php

namespace App\Support\Platform;

use App\Models\PlatformAuditLog;
use App\Models\Vote;
use App\Models\VoteEntry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The only code path that creates votes or changes vote_entries.votes_count.
 *
 * Anti-abuse foundation (no paid SMS/captcha service is configured, so
 * there is no OTP yet — see config/platform.php):
 *   - one vote per phone number, per category, per period — enforced by
 *     the votes.uq_vote unique index, not just an application check
 *   - campaigns with vote_limit 'program': one vote per phone number in the
 *     whole award programme (every category, every campaign of that award),
 *     checked under a per-number lock so parallel requests can't both pass
 *   - a device cookie may vote for at most N different phone numbers per
 *     category per period (blocks one person cycling fake numbers)
 *   - a hard daily cap per IP per campaign; softer per-IP thresholds only
 *     *flag* votes, because Bangladeshi mobile carriers put many real users
 *     behind one CGNAT IP
 *   - flagged votes still count until a Super Admin invalidates them;
 *     every invalidation/restoration is audit-logged with a reason
 * Request-level throttling, the honeypot and the optional Turnstile check
 * live in Platform\VoteController.
 */
class VoteService
{
    /** 01XXXXXXXXX, or null when it isn't a Bangladeshi mobile number. */
    public static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    public static function voterHash(string $phone): string
    {
        return hash_hmac('sha256', 'phone:'.$phone, (string) config('app.key'));
    }

    public static function deviceHash(?string $deviceId): ?string
    {
        return $deviceId ? hash_hmac('sha256', 'device:'.$deviceId, (string) config('app.key')) : null;
    }

    public static function mask(string $phone): string
    {
        return substr($phone, 0, 3).'•••••'.substr($phone, -3);
    }

    /**
     * @throws VoteRejected with a message safe to show the voter
     */
    public function cast(VoteEntry $entry, string $phone, ?string $deviceId, ?string $ip, ?string $userAgent): Vote
    {
        $normalized = self::normalizePhone($phone) ?? throw new VoteRejected('Enter a valid Bangladeshi mobile number (e.g. 01712345678).');

        // One number's votes are processed one at a time, so the programme-wide
        // check below can't be raced by parallel submissions.
        try {
            return Cache::lock('platform-vote:'.self::voterHash($normalized), 10)
                ->block(5, fn () => $this->castLocked($entry, $normalized, $deviceId, $ip, $userAgent));
        } catch (LockTimeoutException) {
            throw new VoteRejected('Your vote is already being processed. Please wait a moment.');
        }
    }

    private function castLocked(VoteEntry $entry, string $phone, ?string $deviceId, ?string $ip, ?string $userAgent): Vote
    {
        $entry->loadMissing('campaign.award', 'brand');
        $campaign = $entry->campaign;

        if (! $entry->is_active || ! $campaign || ! $campaign->isOpen() || ! $entry->brand?->isPublished()) {
            throw new VoteRejected('Voting for this brand is not open right now.');
        }

        $voter = self::voterHash($phone);
        $device = self::deviceHash($deviceId);
        $period = $campaign->periodKey();
        $cfg = config('platform.voting');

        if ($campaign->vote_limit === 'program'
            && Vote::whereIn('campaign_id', $campaign->programCampaignIds())->where('voter_hash', $voter)->exists()) {
            throw new VoteRejected('This number has already voted in '.($campaign->award?->title ?? 'this programme').'. Each mobile number can vote only once in the whole programme.');
        }

        $already = Vote::where('vote_category_id', $entry->vote_category_id)->where('voter_hash', $voter)->where('period_key', $period)->exists();
        if ($already) {
            throw new VoteRejected($period === 'once'
                ? 'This number has already voted in this category.'
                : 'This number has already voted in this category today. You can vote again tomorrow.');
        }

        $today = now()->startOfDay();
        if ($ip && Vote::where('campaign_id', $campaign->id)->where('ip', $ip)->where('created_at', '>=', $today)->count() >= $cfg['ip_daily_hard_limit']) {
            throw new VoteRejected('Too many votes from your network today. Please try again tomorrow.');
        }

        $flags = [];
        if ($device) {
            $devicePhones = Vote::where('vote_category_id', $entry->vote_category_id)->where('period_key', $period)
                ->where('device_hash', $device)->distinct()->count('voter_hash');
            if ($devicePhones >= $cfg['device_phone_limit']) {
                throw new VoteRejected('This device has already been used to vote with several numbers in this category.');
            }
            if ($devicePhones > 0) {
                $flags[] = 'shared_device';
            }
        } else {
            $flags[] = 'no_device';
        }

        if ($ip) {
            if (Vote::where('vote_entry_id', $entry->id)->where('ip', $ip)->where('created_at', '>=', now()->subHour())->count() >= $cfg['ip_burst_per_hour']) {
                $flags[] = 'ip_burst';
            }
            if (Vote::where('campaign_id', $campaign->id)->where('ip', $ip)->where('created_at', '>=', $today)->count() >= $cfg['ip_daily_soft_limit']) {
                $flags[] = 'ip_heavy';
            }
        }

        try {
            return DB::transaction(function () use ($entry, $campaign, $voter, $phone, $period, $ip, $device, $userAgent, $flags) {
                $vote = Vote::create([
                    'campaign_id' => $campaign->id,
                    'vote_category_id' => $entry->vote_category_id,
                    'vote_entry_id' => $entry->id,
                    'voter_hash' => $voter,
                    'phone_masked' => self::mask($phone),
                    'period_key' => $period,
                    'ip' => $ip,
                    'device_hash' => $device,
                    'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                    'flags' => $flags ? implode(',', $flags) : null,
                    'status' => 'valid',
                ]);
                VoteEntry::whereKey($entry->id)->increment('votes_count');

                return $vote;
            });
        } catch (QueryException $e) {
            // Lost a race against the same number voting twice at once — uq_vote held.
            throw new VoteRejected('This number has already voted in this category.');
        }
    }

    /** Mark votes invalid (Super Admin only), decrement their entries, audit each change. Returns how many changed. */
    public static function invalidate(Collection $votes, string $reason, ?int $adminId): int
    {
        $changed = 0;
        foreach ($votes as $vote) {
            DB::transaction(function () use ($vote, $reason, $adminId, &$changed) {
                $fresh = Vote::whereKey($vote->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status !== 'valid') {
                    return;
                }
                $fresh->update(['status' => 'invalid', 'invalidated_by' => $adminId, 'invalidated_at' => now(), 'invalid_reason' => mb_substr($reason, 0, 255)]);
                VoteEntry::whereKey($fresh->vote_entry_id)->where('votes_count', '>', 0)->decrement('votes_count');
                PlatformAuditLog::record('vote.invalidated', $fresh, ['status' => ['valid', 'invalid'], 'entry_id' => $fresh->vote_entry_id, 'flags' => $fresh->flags], $reason);
                $changed++;
            });
        }

        return $changed;
    }

    public static function restore(Vote $vote, string $reason): bool
    {
        return DB::transaction(function () use ($vote, $reason) {
            $fresh = Vote::whereKey($vote->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'invalid') {
                return false;
            }
            $fresh->update(['status' => 'valid', 'invalidated_by' => null, 'invalidated_at' => null, 'invalid_reason' => null]);
            VoteEntry::whereKey($fresh->vote_entry_id)->increment('votes_count');
            PlatformAuditLog::record('vote.restored', $fresh, ['status' => ['invalid', 'valid'], 'entry_id' => $fresh->vote_entry_id], $reason);

            return true;
        });
    }
}
