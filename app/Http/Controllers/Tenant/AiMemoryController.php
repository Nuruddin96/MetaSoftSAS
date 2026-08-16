<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AiTenantMemory;
use Illuminate\Http\Request;

/**
 * "Teach Your AI Agent" — tenant-authored Q&A business knowledge
 * (database/sql/chunk41.sql, App\Models\AiTenantMemory). Pure DB reads/
 * writes only — never calls OpenAI, per the task's explicit "don't burn
 * tokens just to save a memory" constraint. Matching a saved Q&A against
 * a real customer message happens later, at AI reply time, in
 * App\Services\AI\AiTenantMemoryService — this controller never touches
 * that. Tenant isolation on update()/destroy() comes from implicit route
 * binding through App\Traits\BelongsToTenant::resolveRouteBinding() (see
 * that trait's docblock) — a route parameter naming another tenant's
 * memory 404s before this controller ever runs.
 */
class AiMemoryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string|max:2000',
        ]);

        AiTenantMemory::create($data);

        return back()->with('success', 'প্রশ্ন-উত্তর সেভ হয়েছে।');
    }

    public function update(Request $request, AiTenantMemory $aiMemory)
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string|max:2000',
        ]);

        $aiMemory->update($data);

        return back()->with('success', 'প্রশ্ন-উত্তর আপডেট হয়েছে।');
    }

    public function destroy(AiTenantMemory $aiMemory)
    {
        $aiMemory->delete();

        return back()->with('success', 'প্রশ্ন-উত্তর মুছে ফেলা হয়েছে।');
    }
}
