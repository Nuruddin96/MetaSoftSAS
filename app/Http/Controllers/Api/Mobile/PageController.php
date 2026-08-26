<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;

/**
 * Mirrors Tenant\WebsiteController's page slice only (storePage/updatePage/
 * destroyPage) — the mobile Storefront Settings feature covers custom pages
 * (এবাউট আস, প্রাইভেসি পলিসি, ইত্যাদি) in addition to banners; the rest of
 * that controller's surface (reviews, homepage/footer text) stays
 * web-panel-only.
 */
class PageController extends Controller
{
    public function index()
    {
        $pages = Page::orderBy('sort_order')->get();

        return response()->json(['data' => $pages->map(fn (Page $p) => $this->present($p))->all()]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $page = Page::create($data + [
            'show_in_footer' => $request->boolean('show_in_footer', true),
            'show_in_header' => $request->boolean('show_in_header'),
            'sort_order' => (int) Page::max('sort_order') + 1,
            'is_active' => 1,
        ]);

        return response()->json($this->present($page), 201);
    }

    public function update(Request $request, int $page)
    {
        $page = Page::where('tenant_id', app('currentTenant')->id)->findOrFail($page);

        $data = $this->validateData($request);

        $page->update($data + [
            'show_in_footer' => $request->boolean('show_in_footer'),
            'show_in_header' => $request->boolean('show_in_header'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($this->present($page));
    }

    public function destroy(int $page)
    {
        $page = Page::where('tenant_id', app('currentTenant')->id)->findOrFail($page);
        $page->delete();

        return response()->json(['ok' => true]);
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:150',
            'page_header' => 'nullable|string|max:200',
            'content' => 'nullable|string|max:50000',
        ]);
    }

    protected function present(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'page_header' => $page->page_header,
            'slug' => $page->slug,
            'content' => $page->content,
            'show_in_header' => (bool) $page->show_in_header,
            'show_in_footer' => (bool) $page->show_in_footer,
            'sort_order' => $page->sort_order,
            'is_active' => (bool) $page->is_active,
        ];
    }
}
