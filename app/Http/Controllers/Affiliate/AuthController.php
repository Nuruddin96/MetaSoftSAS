<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function registerForm()
    {
        return view('affiliate.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:150',
            'email'    => 'required|email|unique:affiliates,email',
            'phone'    => 'required|regex:/^01[3-9][0-9]{8}$/',
            'password' => 'required|min:6|confirmed',
        ], [
            'phone.regex'   => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
            'email.unique'  => 'এই ইমেইলে আগেই অ্যাফিলিয়েট একাউন্ট আছে।',
        ]);

        $affiliate = Affiliate::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'],
            'password' => Hash::make($data['password']),
        ]);

        Auth::guard('affiliate')->login($affiliate);

        return redirect()->route('affiliate.dashboard')->with('success', 'স্বাগতম! আপনার রেফারেল লিংক রেডি।');
    }

    public function loginForm()
    {
        return view('affiliate.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::guard('affiliate')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'ইমেইল বা পাসওয়ার্ড ভুল।'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->route('affiliate.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('affiliate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('affiliate.login');
    }
}
