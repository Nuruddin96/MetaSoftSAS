<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\AwardCategory;
use App\Models\AwardNomination;
use App\Models\AwardRecognition;
use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\PlatformAuditLog;
use App\Models\VoteCampaign;
use App\Support\Platform\PlatformNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Awards without code changes: seasons, categories, nominations
 * (submitted → nominee → shortlisted → finalist) and results. Finalist is
 * a nomination status; Winner, People's Choice and Jury Choice are
 * separate AwardRecognition types — they are never merged.
 */
class AwardController extends Controller
{
    public function index()
    {
        return view('super.platform.awards.index', [
            'awards' => Award::withCount(['categories', 'nominations', 'recognitions'])
                ->withCount(['nominations as pending_nominations' => fn ($q) => $q->where('status', 'submitted')])
                ->latest('year')->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('super.platform.awards.form', ['award' => new Award(['year' => now()->year, 'status' => 'draft'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = Award::uniqueSlug($data['title'], (int) $data['year']);
        $award = Award::create($data);
        PlatformAuditLog::record('award.created', $award, ['title' => $award->title]);

        return redirect()->route('super.awards.show', $award)->with('success', 'Award created. Add its categories next.');
    }

    public function show(Award $award)
    {
        $award->load(['categories.brandCategory', 'recognitions.brand', 'recognitions.category', 'campaigns']);

        return view('super.platform.awards.show', [
            'award' => $award,
            'nominations' => $award->nominations()->with('brand', 'category')
                ->when(request('status'), fn ($q) => $q->where('status', request('status')))
                ->orderBy('award_category_id')->latest()->get()->groupBy('award_category_id'),
            'brandCategories' => BrandCategory::ordered()->get(),
            // Every approved brand can be picked as a nominee; nothing is nominated automatically.
            'approvedBrands' => Brand::published()->with('category')->orderBy('name')->get(['id', 'name', 'slug', 'brand_category_id', 'district', 'is_verified']),
            'nominatedPairs' => $award->nominations()->get(['award_category_id', 'brand_id'])
                ->map(fn ($n) => $n->award_category_id.':'.$n->brand_id)->flip()->all(),
            'statusCounts' => $award->nominations()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function edit(Award $award)
    {
        return view('super.platform.awards.form', ['award' => $award]);
    }

    public function update(Request $request, Award $award)
    {
        $award->fill($this->validated($request));
        $dirty = $award->getDirty();
        $award->save();
        PlatformAuditLog::record('award.updated', $award, $dirty);

        return redirect()->route('super.awards.show', $award)->with('success', 'Award saved.');
    }

    public function destroy(Request $request, Award $award)
    {
        $request->validate(['confirm' => 'required|in:DELETE']);
        PlatformAuditLog::record('award.deleted', $award, ['title' => $award->title, 'nominations' => $award->nominations()->count()]);
        $award->delete();

        return redirect()->route('super.awards.index')->with('success', 'Award deleted.');
    }

    public function storeCategory(Request $request, Award $award)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'brand_category_id' => 'nullable|exists:brand_categories,id',
        ]);
        $data['sort_order'] = (int) $award->categories()->max('sort_order') + 1;
        $category = $award->categories()->create($data);
        PlatformAuditLog::record('award.category_added', $award, ['category' => $category->name]);

        return back()->with('success', 'Category added.');
    }

    public function destroyCategory(AwardCategory $category)
    {
        if ($category->nominations()->exists()) {
            return back()->with('error', 'This category already has nominations, so it can’t be removed.');
        }
        PlatformAuditLog::record('award.category_removed', $category->award, ['category' => $category->name]);
        $category->delete();

        return back()->with('success', 'Category removed.');
    }

    /**
     * Super Admin nominates approved brands into one category: picked from
     * the approved-brand list (brand_ids[]) or typed (brand = id/slug/name).
     * They start as "Nominee" (accepted). Approval or verification alone
     * never creates a nomination.
     */
    public function storeNomination(Request $request, Award $award)
    {
        $data = $request->validate([
            'award_category_id' => ['required', Rule::exists('award_categories', 'id')->where('award_id', $award->id)],
            'brand_ids' => 'nullable|array|max:200',
            'brand_ids.*' => 'integer',
            'brand' => 'nullable|string|max:160',
        ]);
        $category = AwardCategory::findOrFail($data['award_category_id']);

        $brands = Brand::published()->whereIn('id', $data['brand_ids'] ?? [])->get();
        if (filled($data['brand'] ?? null)) {
            $brands->push($this->findBrand($data['brand']));
        }
        if ($brands->isEmpty()) {
            throw ValidationException::withMessages(['brand_ids' => 'Select at least one approved brand.']);
        }

        $added = [];
        $skipped = [];
        foreach ($brands->unique('id') as $brand) {
            if (! $category->accepts($brand)) {
                $skipped[] = $brand->name.' (not eligible)';

                continue;
            }
            $nomination = AwardNomination::firstOrCreate(
                ['award_category_id' => $category->id, 'brand_id' => $brand->id],
                ['award_id' => $award->id, 'source' => 'admin', 'status' => 'accepted'],
            );
            if (! $nomination->wasRecentlyCreated) {
                $skipped[] = $brand->name.' (already nominated)';

                continue;
            }
            PlatformAuditLog::record('nomination.added_by_admin', $nomination, ['brand_id' => $brand->id]);
            PlatformNotifier::owner($brand, 'nomination_status', 'Your brand was nominated: '.$category->name, $award->title, route('owner.awards'));
            $added[] = $brand->name;
        }

        $msg = $added ? count($added).' nominee(s) added to '.$category->name.'.' : 'No nominees added.';
        if ($skipped) {
            $msg .= ' Skipped: '.implode(', ', $skipped).'.';
        }

        return back()->with($added ? 'success' : 'error', $msg);
    }

    /** Move several nominations to one status (Nominee → Shortlisted → Finalist …) in one go. */
    public function bulkNominations(Request $request, Award $award)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => ['required', Rule::in(array_keys(AwardNomination::STATUSES))],
        ]);

        $n = 0;
        foreach ($award->nominations()->whereIn('id', $data['ids'])->get() as $nomination) {
            if ($nomination->status !== $data['status']) {
                $this->applyNominationStatus($nomination, $data['status'], null);
                $n++;
            }
        }

        return back()->with('success', $n.' nomination(s) moved to '.AwardNomination::STATUSES[$data['status']].'.');
    }

    public function updateCategory(Request $request, AwardCategory $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'brand_category_id' => 'nullable|exists:brand_categories,id',
        ]);
        $category->fill($data);
        $dirty = $category->getDirty();
        $category->save();
        PlatformAuditLog::record('award.category_updated', $category->award, ['category_id' => $category->id, 'changes' => $dirty]);

        return back()->with('success', 'Category saved.');
    }

    /**
     * One-click setup of the national programme (config('platform.award_name')):
     * 25 categories (config('platform.award_categories')), each later
     * awarded twice — People's Choice and Jury Choice — plus a draft voting
     * campaign with one vote per phone number for the whole programme.
     * Runs once; afterwards it just opens the existing programme.
     */
    public function setupProgram()
    {
        $title = (string) config('platform.award_name');
        $year = (int) (preg_match('/\b(20\d\d)\b/', $title, $m) ? $m[1] : now()->year);

        if ($existing = Award::where('title', $title)->where('year', $year)->first()) {
            return redirect()->route('super.awards.show', $existing)->with('success', 'This programme is already set up.');
        }

        $award = DB::transaction(function () use ($title, $year) {
            $award = Award::create([
                'title' => $title,
                'bn_title' => config('platform.award_name_bn'),
                'year' => $year,
                'slug' => Award::uniqueSlug($title, $year),
                'status' => 'draft',
                'description' => 'Recognising Bangladeshi brands and entrepreneurs across 25 categories. Every category has two awards: People’s Choice (decided by verified public votes) and Jury Choice (decided by an independent jury).',
                'rules' => "1. Only approved brands on MetaSoft BD can be nominated.\n2. Each category awards People’s Choice and Jury Choice separately.\n3. One mobile number can cast only one vote in the whole programme.\n4. Sponsorship or paid placement never affects nominations, votes or results.",
            ]);
            foreach (config('platform.award_categories') as $i => $name) {
                $award->categories()->create(['name' => $name, 'sort_order' => $i + 1]);
            }

            $campaign = VoteCampaign::create([
                'award_id' => $award->id,
                'title' => $title.' — People’s Choice',
                'slug' => VoteCampaign::uniqueSlug($title.' peoples choice'),
                'status' => 'draft',
                'vote_limit' => 'program',
                'show_counts' => true,
                'description' => 'People’s Choice voting. One mobile number can vote only once in the whole programme.',
            ]);
            foreach ($award->categories()->get() as $ac) {
                $campaign->categories()->create(['award_category_id' => $ac->id, 'name' => $ac->name, 'sort_order' => $ac->sort_order]);
            }

            return $award;
        });

        PlatformAuditLog::record('award.program_setup', $award, ['categories' => $award->categories()->count()]);

        return redirect()->route('super.awards.show', $award)->with('success', 'Programme created as a draft: 25 categories × People’s Choice + Jury Choice, with a draft voting campaign (one vote per number for the whole programme).');
    }

    public function updateNomination(Request $request, AwardNomination $nomination)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(AwardNomination::STATUSES))],
            'admin_note' => 'nullable|string|max:500',
        ]);
        $this->applyNominationStatus($nomination, $data['status'], $data['admin_note'] ?? null);

        return back()->with('success', 'Nomination updated.');
    }

    /** Status change + audit + owner notification, shared by the single and bulk actions. */
    private function applyNominationStatus(AwardNomination $nomination, string $status, ?string $note): void
    {
        $from = $nomination->status;
        $nomination->update(['status' => $status, 'admin_note' => $note ?? $nomination->admin_note]);
        PlatformAuditLog::record('nomination.status', $nomination, ['status' => [$from, $nomination->status]], $note);

        if ($from !== $nomination->status && $nomination->status !== 'withdrawn') {
            $nomination->load('award', 'category', 'brand');
            $title = match ($nomination->status) {
                'finalist' => '🏆 You are a Finalist: '.$nomination->category->name,
                'shortlisted' => 'Your brand was shortlisted: '.$nomination->category->name,
                'accepted' => 'Your nomination was accepted: '.$nomination->category->name,
                'rejected' => 'Nomination update: '.$nomination->category->name,
                default => 'Nomination update: '.$nomination->category->name,
            };
            PlatformNotifier::owner($nomination->brand, $nomination->status === 'finalist' ? 'finalist' : 'nomination_status', $title,
                $nomination->award->title.' — status: '.$nomination->statusLabel().($note ? '. Note: '.$note : ''),
                route('owner.awards'));
        }
    }

    public function storeRecognition(Request $request, Award $award)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(AwardRecognition::TYPES))],
            'award_category_id' => ['nullable', Rule::exists('award_categories', 'id')->where('award_id', $award->id)],
            'brand' => 'required|string|max:160',
            'title' => 'nullable|string|max:200',
        ]);
        $brand = $this->findBrand($data['brand']);

        $exists = AwardRecognition::where(['award_id' => $award->id, 'brand_id' => $brand->id, 'type' => $data['type'], 'award_category_id' => $data['award_category_id'] ?? null])->exists();
        if ($exists) {
            return back()->with('error', 'This recognition is already recorded.');
        }

        $recognition = AwardRecognition::create([
            'award_id' => $award->id,
            'award_category_id' => $data['award_category_id'] ?? null,
            'brand_id' => $brand->id,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
        ]);
        $recognition->load('award');
        PlatformAuditLog::record('recognition.added', $recognition, ['brand_id' => $brand->id, 'type' => $data['type']]);
        PlatformNotifier::owner($brand, 'winner', '🎉 Congratulations — '.$recognition->label(), $award->title, route('owner.awards'));

        return back()->with('success', 'Recognition recorded for '.$brand->name.'.');
    }

    public function destroyRecognition(Request $request, AwardRecognition $recognition)
    {
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        PlatformAuditLog::record('recognition.removed', $recognition, ['brand_id' => $recognition->brand_id, 'type' => $recognition->type], $reason);
        $recognition->delete();

        return back()->with('success', 'Recognition removed.');
    }

    /** "123" (id), a slug, or an exact brand name — approved brands only. */
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
            'bn_title' => 'nullable|string|max:200',
            'year' => 'required|integer|min:2020|max:2100',
            'description' => 'nullable|string|max:5000',
            'rules' => 'nullable|string|max:10000',
            'jury_info' => 'nullable|string|max:5000',
            'status' => ['required', Rule::in(array_keys(Award::STATUSES))],
            'nomination_starts_at' => 'nullable|date',
            'nomination_ends_at' => 'nullable|date|after_or_equal:nomination_starts_at',
            'voting_starts_at' => 'nullable|date',
            'voting_ends_at' => 'nullable|date|after_or_equal:voting_starts_at',
        ]);
        $data['is_featured'] = $request->boolean('is_featured');

        return $data;
    }
}
