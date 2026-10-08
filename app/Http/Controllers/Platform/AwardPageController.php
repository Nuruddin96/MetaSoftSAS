<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Award;

/** Public award page: rules, categories, finalists and results (drafts are never public). */
class AwardPageController extends Controller
{
    public function show(string $slug)
    {
        $award = Award::public()->where('slug', $slug)->with([
            'categories',
            'nominations' => fn ($q) => $q->whereIn('status', ['shortlisted', 'finalist'])->whereHas('brand', fn ($b) => $b->published())->with('brand.category'),
            'recognitions' => fn ($q) => $q->whereHas('brand', fn ($b) => $b->published())->with('brand.category', 'category'),
            'campaigns' => fn ($q) => $q->visible(),
        ])->firstOrFail();

        return view('central.platform.award', ['award' => $award]);
    }
}
