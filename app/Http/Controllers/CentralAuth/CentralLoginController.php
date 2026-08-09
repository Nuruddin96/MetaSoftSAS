<?php

namespace App\Http\Controllers\CentralAuth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Single login form on the central domain: email + password only.
 * Works for ANY tenant's staff — no need to know the shop's subdomain.
 *
 * How it works: on the central domain no tenant is bound, so the
 * BelongsToTenant global scope is inactive and `users` can be searched
 * across every tenant by email. Once authenticated we redirect to that
 * tenant's own panel URL; because both live under metasoftbd.com in
 * path mode, the session cookie carries over automatically.
 */
class CentralLoginController extends Controller
{
    public function show()
    {
        return view('central.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('tenant')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'ইমেইল বা পাসওয়ার্ড ভুল।'])->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = Auth::guard('tenant')->user();
        $tenant = Tenant::find($user->tenant_id);

        if (! $tenant) {
            Auth::guard('tenant')->logout();

            return back()->withErrors(['email' => 'এই একাউন্টের দোকান খুঁজে পাওয়া যায়নি।']);
        }

        return redirect()->away($tenant->url().'/panel');
    }

    public function forgotForm()
    {
        return view('central.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::withoutGlobalScopes()->where('email', $data['email'])->first();

        // Always show the same message — don't reveal whether the email exists.
        $generic = 'এই ইমেইলে একাউন্ট থাকলে একটি রিসেট লিংক পাঠানো হয়েছে।';

        if (! $user) {
            return back()->with('success', $generic);
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $data['email']],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetUrl = route('central.password.reset', ['token' => $token]).'?email='.urlencode($data['email']);

        try {
            Mail::raw(
                "আপনার পাসওয়ার্ড রিসেট করতে এই লিংকে ক্লিক করুন (৬০ মিনিটের জন্য কার্যকর):\n\n$resetUrl\n\nআপনি এই রিকোয়েস্ট না করলে এই মেইলটি উপেক্ষা করুন।",
                function ($msg) use ($data) {
                    $msg->to($data['email'])->subject('পাসওয়ার্ড রিসেট — MetaSoft BD');
                }
            );
        } catch (\Throwable $e) {
            report($e);
            // mail not configured yet — still don't leak account existence
        }

        return back()->with('success', $generic);
    }

    public function resetForm(Request $request, string $token)
    {
        return view('central.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return back()->withErrors(['token' => 'লিংকটি সঠিক নয় বা মেয়াদ শেষ হয়ে গেছে।']);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return back()->withErrors(['token' => 'লিংকের মেয়াদ শেষ — আবার রিসেট করুন।']);
        }

        $user = User::withoutGlobalScopes()->where('email', $data['email'])->first();

        if (! $user) {
            return back()->withErrors(['token' => 'একাউন্ট পাওয়া যায়নি।']);
        }

        $user->update(['password' => Hash::make($data['password'])]);
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        $tenant = Tenant::find($user->tenant_id);

        return redirect()->away(
            ($tenant ? $tenant->url().'/panel/login' : route('central.login'))
        )->with('reset_success', 'পাসওয়ার্ড বদলানো হয়েছে। এখন লগইন করুন।');
    }
}
