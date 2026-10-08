<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signs a suspended brand owner out of the dashboard (Super Admin can suspend an account). */
class EnsureBrandOwnerActive
{
    public function handle(Request $request, Closure $next)
    {
        $owner = Auth::guard('brand_owner')->user();

        if ($owner && $owner->isSuspended()) {
            Auth::guard('brand_owner')->logout();
            $request->session()->regenerateToken();

            return redirect()->route('owner.login')->withErrors(['login' => 'This account has been suspended. Please contact MetaSoft BD support.']);
        }

        return $next($request);
    }
}
