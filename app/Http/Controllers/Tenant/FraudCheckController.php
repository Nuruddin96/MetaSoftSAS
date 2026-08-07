<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\FraudChecker;
use Illuminate\Http\Request;

class FraudCheckController extends Controller
{
    public function check(Request $request, FraudChecker $checker)
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);

        return response()->json($checker->check($data['phone']));
    }
}
