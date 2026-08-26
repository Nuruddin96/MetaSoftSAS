<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\FraudChecker;
use Illuminate\Http\Request;

/** Mirrors Tenant\FraudCheckController::check() exactly — same FraudChecker service, same response shape. */
class FraudCheckController extends Controller
{
    public function check(Request $request, FraudChecker $checker)
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);

        return response()->json($checker->check($data['phone'], $request->user()->id) + [
            'internal' => $checker->internalHistory($data['phone']),
        ]);
    }
}
