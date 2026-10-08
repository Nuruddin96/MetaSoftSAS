<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        return view('brand-owner.account', ['owner' => $request->user('brand_owner')]);
    }

    public function updatePassword(Request $request)
    {
        $owner = $request->user('brand_owner');
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($data['current_password'], $owner->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $owner->update(['password' => $data['password']]);

        return back()->with('success', 'Password updated.');
    }
}
