<?php

namespace App\Http\Controllers;

use App\Models\Plan;

class LandingController extends Controller
{
    public function index()
    {
        return view('central.landing', [
            'plans' => Plan::where('is_active', 1)->orderBy('sort_order')->get(),
        ]);
    }
}
