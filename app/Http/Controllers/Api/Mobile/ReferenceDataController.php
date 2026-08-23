<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only bd_divisions/bd_districts/bd_upazilas lookups — not tenant-scoped (shared reference data). */
class ReferenceDataController extends Controller
{
    public function divisions()
    {
        $rows = DB::table('bd_divisions')->orderBy('id')->get(['id', 'name', 'bn_name']);

        return response()->json(['data' => $rows]);
    }

    /**
     * division_id is optional (not required, as it used to be) — the
     * District→Thana order/customer address UX (Priority 2) lists all
     * districts up front with no division step first, matching the web
     * panel's own unfiltered district `<select>`. Any existing caller that
     * still passes division_id (e.g. onboarding's business-address form)
     * keeps its filtered behavior unchanged.
     */
    public function districts(Request $request)
    {
        $request->validate(['division_id' => 'nullable|integer']);

        $rows = DB::table('bd_districts')
            ->when($request->filled('division_id'), fn ($q) => $q->where('division_id', $request->integer('division_id')))
            ->orderBy('name')
            ->get(['id', 'division_id', 'name', 'bn_name']);

        return response()->json(['data' => $rows]);
    }

    /** Thanas/upazilas for one district — the dynamic step after District in the new address UX. */
    public function upazilas(Request $request)
    {
        $request->validate(['district_id' => 'required|integer']);

        $rows = DB::table('bd_upazilas')
            ->where('district_id', $request->integer('district_id'))
            ->orderBy('name')
            ->get(['id', 'district_id', 'name', 'bn_name']);

        return response()->json(['data' => $rows]);
    }
}
