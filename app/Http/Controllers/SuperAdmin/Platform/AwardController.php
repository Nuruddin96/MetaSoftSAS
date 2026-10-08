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
use App\Support\Platform\PlatformNotifier;
use Illuminate\Http\Request;
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

    /** Super Admin nominates a brand directly (e.g. editorial or jury nomination). */
    public function storeNomination(Request $request, Award $award)
    {
        $data = $request->validate([
            'award_category_id' => ['required', Rule::exists('award_categories', 'id')->where('award_id', $award->id)],
            'brand' => 'required|string|max:160',
        ]);
        $brand = $this->findBrand($data['brand']);

        $nomination = AwardNomination::firstOrCreate(
            ['award_category_id' => $data['award_category_id'], 'brand_id' => $brand->id],
            ['award_id' => $award->id, 'source' => 'admin', 'status' => 'accepted'],
        );
        if (! $nomination->wasRecentlyCreated) {
            return back()->with('error', $brand->name.' is already nominated in this category.');
        }

        PlatformAuditLog::record('nomination.added_by_admin', $nomination, ['brand_id' => $brand->id]);
        PlatformNotifier::owner($brand, 'nomination_status', 'Your brand was nominated: '.$nomination->category->name,
            $award->title, route('owner.awards'));

        return back()->with('success', $brand->name.' nominated.');
    }

    public function updateNomination(Request $request, AwardNomination $nomination)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(AwardNomination::STATUSES))],
            'admin_note' => 'nullable|string|max:500',
        ]);
        $from = $nomination->status;
        $nomination->update($data);
        PlatformAuditLog::record('nomination.status', $nomination, ['status' => [$from, $nomination->status]], $data['admin_note'] ?? null);

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
                $nomination->award->title.' — status: '.$nomination->statusLabel().($data['admin_note'] ?? null ? '. Note: '.$data['admin_note'] : ''),
                route('owner.awards'));
        }

        return back()->with('success', 'Nomination updated.');
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
