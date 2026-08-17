@extends('layouts.super')

@section('title', 'কাস্টম ডোমেইন রিকোয়েস্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">🌐 কাস্টম ডোমেইন রিকোয়েস্ট</h1>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-mute border-b border-ink/10">
                <th class="p-4">টেনেন্ট</th>
                <th class="p-4">কাস্টম ডোমেইন</th>
                <th class="p-4">স্ট্যাটাস</th>
                <th class="p-4">কানেকশন</th>
                <th class="p-4">DNS</th>
                <th class="p-4">SSL</th>
                <th class="p-4">টেনেন্ট যোগ হয়েছে</th>
                <th class="p-4">সর্বশেষ আপডেট</th>
                <th class="p-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tenants as $tenant)
                @php
                    $status = $tenant->customDomainDisplayStatus();
                    $statusLabel = ['pending' => 'Pending', 'approved' => 'Approved', 'active' => 'Active', 'rejected' => 'Rejected', 'none' => '—'][$status];
                    $connStatus = $tenant->customDomainConnectionStatus();
                    // Connection/DNS/SSL are one derived reading of the same
                    // Cloudflare sub-state, not three independently tracked
                    // values — see Tenant::customDomainConnectionStatus().
                    $connLabel = ['dns_required' => 'DNS Required', 'connecting' => 'Connecting', 'connected' => 'Connected', 'failed' => 'Failed'][$connStatus] ?? ($status === 'active' ? 'Connected' : '—');
                    $dnsLabel = $status === 'active' ? 'OK' : ($connStatus === 'dns_required' ? 'Required' : ($connStatus === 'failed' ? 'Failed' : '—'));
                    $sslLabel = $status === 'active' ? 'Active' : (in_array($connStatus, ['connecting', 'connected'], true) ? 'Pending' : '—');
                @endphp
                <tr class="border-b border-ink/5">
                    <td class="p-4"><a href="{{ route('super.tenants.show', $tenant) }}" class="text-leaf hover:underline font-semibold">{{ $tenant->store_name }}</a></td>
                    <td class="p-4 font-mono text-xs">{{ $tenant->custom_domain ?? $tenant->custom_domain_requested ?? '—' }}</td>
                    <td class="p-4">
                        <span @class([
                            'px-2 py-1 rounded text-xs font-semibold',
                            'bg-leaf/10 text-leafdk' => $status === 'active',
                            'bg-amber/15 text-amber-700' => in_array($status, ['pending', 'approved']),
                            'bg-red-50 text-red-600' => $status === 'rejected',
                            'bg-ink/5 text-mute' => $status === 'none',
                        ])>{{ $statusLabel }}</span>
                    </td>
                    <td class="p-4 text-xs">{{ $connLabel }}</td>
                    <td class="p-4 text-xs">{{ $dnsLabel }}</td>
                    <td class="p-4 text-xs">{{ $sslLabel }}</td>
                    <td class="p-4 text-xs text-mute">{{ $tenant->created_at?->format('d M Y') }}</td>
                    <td class="p-4 text-xs text-mute">{{ $tenant->updated_at?->format('d M Y, h:i A') }}</td>
                    <td class="p-4"><a href="{{ route('super.tenants.show', $tenant) }}" class="text-leaf text-xs hover:underline">ম্যানেজ করুন →</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-6 text-center text-mute text-sm">এখনো কোনো কাস্টম ডোমেইন রিকোয়েস্ট নেই।</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $tenants->links() }}</div>
@endsection
