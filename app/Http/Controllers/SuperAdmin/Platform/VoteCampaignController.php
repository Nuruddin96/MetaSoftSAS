<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Brand;
use App\Models\PlatformAuditLog;
use App\Models\Vote;
use App\Models\VoteCampaign;
use App\Models\VoteCategory;
use App\Models\VoteEntry;
use App\Support\Platform\CampaignLifecycle;
use App\Support\Platform\PlatformNotifier;
use App\Support\Platform\VoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Voting campaigns: setup, start/pause/resume/end, nominees, rankings,
 * suspicious-activity review and audited vote invalidation. Vote counts
 * only ever change through VoteService (casting, invalidation, restore).
 */
class VoteCampaignController extends Controller
{
    public function index()
    {
        return view('super.platform.campaigns.index', [
            'campaigns' => VoteCampaign::with('award')->withCount([
                'entries',
                'votes as valid_votes' => fn ($q) => $q->where('status', 'valid'),
                'votes as flagged_votes' => fn ($q) => $q->where('status', 'valid')->whereNotNull('flags'),
            ])->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('super.platform.campaigns.form', [
            'campaign' => new VoteCampaign(['vote_limit' => 'daily', 'show_counts' => true, 'award_id' => request('award')]),
            'awards' => Award::latest('year')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = VoteCampaign::uniqueSlug($data['title']);
        $data['status'] = 'draft';
        $campaign = VoteCampaign::create($data);
        PlatformAuditLog::record('campaign.created', $campaign, ['title' => $campaign->title]);

        return redirect()->route('super.campaigns.show', $campaign)->with('success', 'Campaign created as a draft. Add categories and nominees, then start it.');
    }

    public function show(VoteCampaign $campaign)
    {
        $campaign->load(['award.categories', 'categories.entries' => fn ($q) => $q->with('brand')->orderByDesc('votes_count')->orderBy('id')]);

        return view('super.platform.campaigns.show', [
            'campaign' => $campaign,
            'stats' => [
                'valid' => $campaign->votes()->where('status', 'valid')->count(),
                'invalid' => $campaign->votes()->where('status', 'invalid')->count(),
                'flagged' => $campaign->votes()->where('status', 'valid')->whereNotNull('flags')->count(),
                'today' => $campaign->votes()->where('created_at', '>=', now()->startOfDay())->count(),
            ],
        ]);
    }

    public function edit(VoteCampaign $campaign)
    {
        return view('super.platform.campaigns.form', ['campaign' => $campaign, 'awards' => Award::latest('year')->get()]);
    }

    public function update(Request $request, VoteCampaign $campaign)
    {
        $campaign->fill($this->validated($request));
        $dirty = $campaign->getDirty();
        $campaign->save();
        PlatformAuditLog::record('campaign.updated', $campaign, $dirty);
        CampaignLifecycle::sync($campaign->fresh());

        return redirect()->route('super.campaigns.show', $campaign)->with('success', 'Campaign saved.');
    }

    public function destroy(Request $request, VoteCampaign $campaign)
    {
        if ($campaign->votes()->exists()) {
            return back()->with('error', 'This campaign already has votes. End it instead of deleting it, so the vote record is kept.');
        }
        PlatformAuditLog::record('campaign.deleted', $campaign, ['title' => $campaign->title]);
        $campaign->delete();

        return redirect()->route('super.campaigns.index')->with('success', 'Campaign deleted.');
    }

    /** start | pause | resume | end */
    public function status(Request $request, VoteCampaign $campaign)
    {
        $data = $request->validate(['action' => 'required|in:start,pause,resume,end', 'reason' => 'nullable|string|max:500']);
        $from = $campaign->status;

        $to = match ($data['action']) {
            'start' => in_array($from, ['draft'], true) ? 'active' : null,
            'pause' => $from === 'active' ? 'paused' : null,
            'resume' => $from === 'paused' ? 'active' : null,
            'end' => in_array($from, ['active', 'paused'], true) ? 'ended' : null,
        };
        if (! $to) {
            return back()->with('error', "Can't {$data['action']} a campaign that is {$from}.");
        }
        if ($data['action'] === 'start' && ! $campaign->entries()->where('is_active', true)->exists()) {
            return back()->with('error', 'Add at least one nominee before starting voting.');
        }

        $campaign->update(['status' => $to]);
        PlatformAuditLog::record('campaign.'.$data['action'], $campaign, ['status' => [$from, $to]], $data['reason'] ?? null);
        CampaignLifecycle::sync($campaign->fresh());

        return back()->with('success', 'Campaign '.$campaign->fresh()->phaseLabel().'.');
    }

    public function storeCategory(Request $request, VoteCampaign $campaign)
    {
        $data = $request->validate(['name' => 'required|string|max:150']);
        $category = $campaign->categories()->create(['name' => $data['name'], 'sort_order' => (int) $campaign->categories()->max('sort_order') + 1]);
        PlatformAuditLog::record('campaign.category_added', $campaign, ['category' => $category->name]);

        return back()->with('success', 'Category added.');
    }

    public function destroyCategory(VoteCategory $category)
    {
        if (Vote::where('vote_category_id', $category->id)->exists()) {
            return back()->with('error', 'This category already has votes and can’t be removed.');
        }
        PlatformAuditLog::record('campaign.category_removed', $category->campaign, ['category' => $category->name]);
        $category->delete();

        return back()->with('success', 'Category removed.');
    }

    public function storeEntry(Request $request, VoteCampaign $campaign)
    {
        $data = $request->validate([
            'vote_category_id' => ['required', Rule::exists('vote_categories', 'id')->where('campaign_id', $campaign->id)],
            'brand' => 'required|string|max:160',
        ]);
        $brand = $this->findBrand($data['brand']);

        $entry = VoteEntry::firstOrCreate(
            ['vote_category_id' => $data['vote_category_id'], 'brand_id' => $brand->id],
            ['campaign_id' => $campaign->id, 'is_active' => true],
        );
        if (! $entry->wasRecentlyCreated) {
            return back()->with('error', $brand->name.' is already in this category.');
        }

        PlatformAuditLog::record('campaign.entry_added', $campaign, ['brand_id' => $brand->id, 'category_id' => $entry->vote_category_id]);
        $this->notifyEntry($campaign, $entry->load('category', 'brand'));

        return back()->with('success', $brand->name.' added.');
    }

    public function toggleEntry(Request $request, VoteEntry $entry)
    {
        $entry->update(['is_active' => ! $entry->is_active]);
        PlatformAuditLog::record($entry->is_active ? 'campaign.entry_enabled' : 'campaign.entry_disabled', $entry->campaign, ['entry_id' => $entry->id, 'brand_id' => $entry->brand_id], $request->input('reason'));

        return back()->with('success', $entry->is_active ? 'Nominee re-enabled.' : 'Nominee removed from voting (votes kept).');
    }

    /** Copy the linked award's categories and nominees (by nomination status) into this campaign. */
    public function importFromAward(Request $request, VoteCampaign $campaign)
    {
        $award = $campaign->award ?? abort(404);
        $statuses = $request->validate([
            'statuses' => 'required|array|min:1',
            'statuses.*' => 'in:accepted,shortlisted,finalist',
        ])['statuses'];

        $added = 0;
        DB::transaction(function () use ($award, $campaign, $statuses, &$added) {
            foreach ($award->categories as $ac) {
                $category = $campaign->categories()->firstOrCreate(['award_category_id' => $ac->id], ['name' => $ac->name, 'sort_order' => $ac->sort_order]);
                $brandIds = $ac->nominations()->whereIn('status', $statuses)->whereHas('brand', fn ($b) => $b->published())->pluck('brand_id');
                foreach ($brandIds as $brandId) {
                    $entry = VoteEntry::firstOrCreate(['vote_category_id' => $category->id, 'brand_id' => $brandId], ['campaign_id' => $campaign->id, 'is_active' => true]);
                    if ($entry->wasRecentlyCreated) {
                        $added++;
                        $this->notifyEntry($campaign, $entry->load('category', 'brand'));
                    }
                }
            }
        });
        PlatformAuditLog::record('campaign.imported_from_award', $campaign, ['award_id' => $award->id, 'statuses' => $statuses, 'added' => $added]);

        return back()->with('success', "Imported {$added} nominee(s) from {$award->title}.");
    }

    public function votes(Request $request, VoteCampaign $campaign)
    {
        $votes = $campaign->votes()->with('entry.brand', 'entry.category')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('flagged'), fn ($q) => $q->whereNotNull('flags'))
            ->when($request->query('ip'), fn ($q, $ip) => $q->where('ip', $ip))
            ->when($request->query('device'), fn ($q, $d) => $q->where('device_hash', $d))
            ->when($request->query('entry'), fn ($q, $e) => $q->where('vote_entry_id', (int) $e))
            ->latest('id')->paginate(50)->withQueryString();

        $since = now()->subDay();

        return view('super.platform.campaigns.votes', [
            'campaign' => $campaign,
            'votes' => $votes,
            'topIps' => $campaign->votes()->where('status', 'valid')->where('created_at', '>=', $since)->whereNotNull('ip')
                ->select('ip', DB::raw('COUNT(*) as n'), DB::raw('COUNT(DISTINCT vote_entry_id) as entries'))
                ->groupBy('ip')->orderByDesc('n')->limit(10)->get(),
            'sharedDevices' => $campaign->votes()->where('status', 'valid')->whereNotNull('device_hash')
                ->select('device_hash', DB::raw('COUNT(DISTINCT voter_hash) as phones'), DB::raw('COUNT(*) as n'))
                ->groupBy('device_hash')->havingRaw('COUNT(DISTINCT voter_hash) > 1')->orderByDesc('phones')->limit(10)->get(),
        ]);
    }

    /** Invalidate selected votes, or every valid vote from one IP / device in this campaign. Reason required. */
    public function invalidate(Request $request, VoteCampaign $campaign)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:255',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'ip' => 'nullable|string|max:45',
            'device' => 'nullable|string|size:64',
        ]);
        if (empty($data['ids']) && empty($data['ip']) && empty($data['device'])) {
            throw ValidationException::withMessages(['ids' => 'Select votes, or give an IP or device to invalidate.']);
        }

        $votes = $campaign->votes()->where('status', 'valid')
            ->where(function ($q) use ($data) {
                if (! empty($data['ids'])) {
                    $q->orWhereIn('id', $data['ids']);
                }
                if (! empty($data['ip'])) {
                    $q->orWhere('ip', $data['ip']);
                }
                if (! empty($data['device'])) {
                    $q->orWhere('device_hash', $data['device']);
                }
            })->get();

        $n = VoteService::invalidate($votes, $data['reason'], auth('super_admin')->id());
        PlatformAuditLog::record('campaign.votes_invalidated', $campaign, ['count' => $n, 'ip' => $data['ip'] ?? null, 'device' => $data['device'] ?? null, 'ids' => $data['ids'] ?? null], $data['reason']);

        return back()->with('success', "{$n} vote(s) invalidated and removed from the totals.");
    }

    public function restoreVote(Request $request, Vote $vote)
    {
        $reason = $request->validate(['reason' => 'required|string|min:5|max:255'])['reason'];

        return VoteService::restore($vote, $reason)
            ? back()->with('success', 'Vote restored and counted again.')
            : back()->with('error', 'Only invalidated votes can be restored.');
    }

    public function export(VoteCampaign $campaign)
    {
        PlatformAuditLog::record('campaign.exported', $campaign);
        $filename = 'votes-'.$campaign->slug.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['vote_id', 'time', 'category', 'brand', 'phone (masked)', 'ip', 'device', 'flags', 'status', 'invalid_reason']);
            $campaign->votes()->with('entry.brand', 'entry.category')->orderBy('id')->chunk(1000, function ($votes) use ($out) {
                foreach ($votes as $v) {
                    fputcsv($out, [$v->id, $v->created_at, $v->entry?->category?->name, $v->entry?->brand?->name, $v->phone_masked, $v->ip, $v->device_hash ? substr($v->device_hash, 0, 12) : '', $v->flags, $v->status, $v->invalid_reason]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function notifyEntry(VoteCampaign $campaign, VoteEntry $entry): void
    {
        if ($campaign->status === 'draft') {
            return;
        }
        PlatformNotifier::owner($entry->brand, 'voting_entry', 'Your brand is in '.$campaign->title,
            'Category: '.$entry->category->name.'. Share your voting link: '.$entry->brand->voteUrl(), route('owner.voting'));
    }

    private function findBrand(string $ref): Brand
    {
        $ref = trim($ref);
        $brand = Brand::published()
            ->where(fn ($q) => ctype_digit($ref) ? $q->whereKey((int) $ref) : $q->where('slug', $ref)->orWhere('name_key', Brand::nameKey($ref)))
            ->first();

        return $brand ?? throw ValidationException::withMessages(['brand' => 'No approved brand matches “'.$ref.'”. Use its ID, URL slug or exact name.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'award_id' => 'nullable|exists:awards,id',
            'description' => 'nullable|string|max:5000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'vote_limit' => 'required|in:'.implode(',', array_keys(VoteCampaign::VOTE_LIMITS)),
        ]);
        $data['show_counts'] = $request->boolean('show_counts');

        return $data;
    }
}
