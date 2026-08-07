@extends('layouts.super')

@section('title', 'টেনেন্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">টেনেন্ট</h1>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="দোকান / ফোন / ইমেইল..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-full md:w-72 focus:ring-2 focus:ring-leaf outline-none">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব স্ট্যাটাস</option>
        @foreach (['trial', 'active', 'expired', 'suspended'] as $st)
            <option value="{{ $st }}" @selected(request('status') === $st)>{{ $st }}</option>
        @endforeach
    </select>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">দোকান</th><th class="px-4 py-3">মালিক</th>
            <th class="px-4 py-3">প্ল্যান</th><th class="px-4 py-3">স্ট্যাটাস</th><th class="px-4 py-3">মেয়াদ</th>
        </tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60 cursor-pointer"
                onclick="window.location='{{ route('super.tenants.show', $t) }}'">
                <td class="px-4 py-3"><p class="font-medium">{{ $t->store_name }}</p><p class="text-xs text-mute">{{ $t->subdomain }}</p></td>
                <td class="px-4 py-3">{{ $t->owner_name }}<br><span class="text-xs text-mute">{{ $t->owner_phone }}</span></td>
                <td class="px-4 py-3">{{ $t->plan?->name }}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 rounded text-xs {{ ['active' => 'bg-leaf/10 text-leafdk', 'trial' => 'bg-amber/15', 'expired' => 'bg-red-50 text-red-600', 'suspended' => 'bg-red-50 text-red-600'][$t->status] ?? 'bg-ink/5' }}">{{ $t->status }}</span>
                    @if ($t->custom_domain_request_status === 'pending')
                        <span class="ml-1 px-2 py-1 rounded text-xs bg-amber/20 text-amber font-semibold">🌐 ডোমেইন পেন্ডিং</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-xs text-mute">
                    {{ $t->status === 'trial' ? 'ট্রায়াল: ' . $t->trial_ends_at?->format('d M Y') : ($t->subscription_ends_at?->format('d M Y') ?? '—') }}
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-12 text-center text-mute">কোনো টেনেন্ট নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $tenants->links() }}</div>
@endsection
