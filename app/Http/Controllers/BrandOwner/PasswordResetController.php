<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\BrandOwner;
use App\Models\PlatformAuditLog;
use App\Support\Platform\PlatformSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Brand owner "forgot password" — Laravel's password broker on its own
 * `brand_owners` broker/table (config/auth.php, database/sql/chunk66.sql),
 * so it can never touch tenant panel accounts or their reset tokens.
 * Tokens are stored hashed, expire after 60 minutes, are deleted on use,
 * and one email can request a new link at most once a minute.
 *
 * Every answer is the same whether or not the email has an account. Until
 * chunk66.sql is imported the page falls back to WhatsApp support.
 */
class PasswordResetController extends Controller
{
    public const SENT = 'If an account exists for that email, we have sent a password reset link. Please check your inbox and spam folder.';

    public const INVALID = 'This reset link is invalid or has expired. Please request a new one.';

    public function request()
    {
        if (Auth::guard('brand_owner')->check()) {
            return redirect()->route('owner.dashboard');
        }

        return view('brand-owner.forgot-password', [
            'available' => PlatformSchema::reviewReady(),
            'whatsapp' => preg_replace('/\D/', '', (string) config('payment.support_whatsapp')),
        ]);
    }

    public function email(Request $request)
    {
        if (! PlatformSchema::reviewReady()) {
            return redirect()->route('owner.password.request');
        }

        $email = strtolower(trim((string) $request->validate(['email' => 'required|email:rfc|max:150'])['email']));
        $owner = BrandOwner::where('email', $email)->first();

        // Suspended accounts get the same answer but no link.
        if ($owner && ! $owner->isSuspended()) {
            try {
                Password::broker('brand_owners')->sendResetLink(['email' => $email]);
            } catch (\Throwable $e) {
                // Mail failure must not reveal that the account exists.
                report($e);
            }
        }

        return back()->with('success', self::SENT);
    }

    public function resetForm(Request $request, string $token)
    {
        if (! PlatformSchema::reviewReady()) {
            return redirect()->route('owner.password.request');
        }

        return view('brand-owner.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request)
    {
        if (! PlatformSchema::reviewReady()) {
            return redirect()->route('owner.password.request');
        }

        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email:rfc|max:150',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::broker('brand_owners')->reset(
            ['email' => strtolower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password']],
            function (BrandOwner $owner, string $password) {
                // `password` is a hashed cast; a new remember token signs out "keep me logged in" devices.
                $owner->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                if ($owner->brand) {
                    PlatformAuditLog::record('owner.password_self_reset', $owner->brand, ['owner_id' => $owner->id]);
                }
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => self::INVALID])->onlyInput('email');
        }

        return redirect()->route('owner.login')->with('success', 'Your password has been changed. You can now log in.');
    }
}
