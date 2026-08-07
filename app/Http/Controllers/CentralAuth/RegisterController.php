<?php

namespace App\Http\Controllers\CentralAuth;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    /** Subdomains no tenant may ever take */
    public const RESERVED = [
        'www', 'mail', 'ftp', 'cpanel', 'webmail', 'admin', 'api', 'app',
        'business', 'blog', 'shop', 'store', 'panel', 'super-admin', 'superadmin',
        'support', 'help', 'billing', 'pay', 'cdn', 'static', 'test', 'dev', 'demo',
    ];

    public function show(\Illuminate\Http\Request $request)
    {
        return view('central.register', ['ref' => $request->query('ref')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_name'  => ['required', 'string', 'max:100'],
            'owner_name'  => ['required', 'string', 'max:100'],
            'owner_phone' => ['required', 'regex:/^01[3-9][0-9]{8}$/'],
            'owner_email' => ['required', 'email', 'unique:tenants,owner_email'],
            'password'    => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'owner_phone.regex'  => 'সঠিক বাংলাদেশি মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
            'owner_email.unique' => 'এই ইমেইলে আগেই একাউন্ট খোলা হয়েছে।',
            'password.confirmed' => 'দুইবার দেয়া পাসওয়ার্ড মিলছে না।',
        ]);

        // FEATURE: subdomain = first word of the business name, auto-generated
        $subdomain = $this->makeSubdomain($data['store_name']);

        if ($subdomain === null) {
            return back()->withInput()->withErrors([
                'store_name' => 'ওয়েবসাইট ঠিকানা তৈরির জন্য বিজনেসের নামটি ইংরেজি অক্ষরে লিখুন (যেমন: Rahim Fashion House)।',
            ]);
        }

        $referrer = null;
        if ($request->filled('ref')) {
            $referrer = Affiliate::where('referral_code', strtoupper($request->input('ref')))
                ->where('status', 'active')->first();
        }

        $tenant = DB::transaction(function () use ($data, $subdomain, $referrer) {
            $tenant = Tenant::create([
                'store_name'  => $data['store_name'],
                'subdomain'   => $subdomain,
                'owner_name'  => $data['owner_name'],
                'owner_phone' => $data['owner_phone'],
                'owner_email' => $data['owner_email'],
                'status'      => 'trial',
                'plan_id'     => 1, // Starter during trial
                'referred_by_affiliate_id' => $referrer?->id,
            ]);

            User::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name'      => $data['owner_name'],
                'email'     => $data['owner_email'],
                'phone'     => $data['owner_phone'],
                'password'  => Hash::make($data['password']),
                'role'      => 'owner',
            ]);

            return $tenant;
        });

        // Their site is now live — send them to their panel
        return redirect()->away($tenant->url() . '/panel/login?registered=1');
    }

    /**
     * "Rahim Fashion House" -> "rahim"
     * "rahim's shop"        -> "rahims"
     * Taken/reserved        -> "rahim2", "rahim3", ...
     * Bengali-only name     -> null (caller shows a friendly error)
     */
    public static function makeSubdomain(string $storeName): ?string
    {
        $firstWord = strtok(trim($storeName), " \t\n");
        $base = preg_replace('/[^a-z0-9]/', '', strtolower((string) $firstWord));

        // must start with a letter/number and be at least 2 chars
        if ($base === '' || strlen($base) < 2) {
            return null;
        }

        $base = substr($base, 0, 30);
        $candidate = $base;
        $i = 2;

        while (
            in_array($candidate, self::RESERVED, true) ||
            Tenant::where('subdomain', $candidate)->exists()
        ) {
            $candidate = $base . $i;
            $i++;
        }

        return $candidate;
    }
}
