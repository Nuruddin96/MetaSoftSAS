<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\BrandOwner;
use App\Support\Platform\VoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/** Brand owner login — by email or mobile number, on the brand_owner guard. */
class AuthController extends Controller
{
    public function show()
    {
        if (Auth::guard('brand_owner')->check()) {
            return redirect()->route('owner.dashboard');
        }

        return view('brand-owner.login', [
            'whatsapp' => preg_replace('/\D/', '', (string) config('payment.support_whatsapp')),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => 'required|string|max:150',
            'password' => 'required|string',
        ]);

        $login = trim($data['login']);
        $phone = VoteService::normalizePhone($login);
        $owner = str_contains($login, '@')
            ? BrandOwner::where('email', strtolower($login))->first()
            : ($phone ? BrandOwner::where('phone', $phone)->first() : null);

        if (! $owner || ! Hash::check($data['password'], $owner->password)) {
            return back()->withErrors(['login' => 'Email/phone or password is incorrect.'])->onlyInput('login');
        }

        if ($owner->isSuspended()) {
            return back()->withErrors(['login' => 'This account has been suspended. Please contact MetaSoft BD support.'])->onlyInput('login');
        }

        Auth::guard('brand_owner')->login($owner, $request->boolean('remember', true));
        $request->session()->regenerate();
        $owner->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('owner.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('brand_owner')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('owner.login');
    }
}
