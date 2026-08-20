<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only bd_divisions/bd_districts lookups — not tenant-scoped (shared reference data). */
class ReferenceDataController extends Controller
{
    public function divisions()
    {
        $rows = DB::table('bd_divisions')->orderBy('id')->get(['id', 'name', 'bn_name']);

        return response()->json(['data' => $rows]);
    }

    public function districts(Request $request)
    {
        $request->validate(['division_id' => 'required|integer']);

        $rows = DB::table('bd_districts')
            ->where('division_id', $request->integer('division_id'))
            ->orderBy('name')
            ->get(['id', 'division_id', 'name', 'bn_name']);

        return response()->json(['data' => $rows]);
    }
}
