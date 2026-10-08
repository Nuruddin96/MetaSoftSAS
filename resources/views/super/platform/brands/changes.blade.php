@extends('layouts.super')

@section('title', 'Change requests — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $fieldNames = ['name' => 'Brand name', 'logo_path' => 'Logo', 'brand_category_id' => 'Category', 'district' => 'District', 'division' => 'Division', 'founder_name' => 'Founder', 'phone' => 'Phone', 'email' => 'Email'];
    $show = fn ($f, $v) => $f === 'brand_category_id' ? ($categories[$v] ?? '—') : ($v ?? '—');
@endphp
<h1 class="mb-1 font-disp text-2xl font-bold">✏️ Profile change requests</h1>
<p class="mb-5 text-sm text-mute">Owners of approved brands request changes to identity fields; the current public details stay until you approve.</p>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="space-y-4">
    @forelse($requests as $req)
        <section class="rounded-xl border border-ink/5 bg-white p-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <a href="{{ route('super.brands.show', $req->brand) }}" class="font-semibold text-leaf hover:underline">{{ $req->brand->name }}</a>
                <span class="text-xs text-mute">{{ $req->updated_at->diffForHumans() }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-mute"><th class="py-1 pr-3">Field</th><th class="py-1 pr-3">Original</th><th class="py-1">Requested</th></tr></thead>
                    <tbody>
                        @foreach($req->changes as $f => $v)
                            <tr class="border-t border-ink/5">
                                <td class="py-2 pr-3 font-semibold">{{ $fieldNames[$f] ?? $f }}</td>
                                @if($f === 'logo_path')
                                    <td class="py-2 pr-3">@if($req->brand->logoUrl())<img src="{{ $req->brand->logoUrl() }}" alt="" class="h-10 w-10 rounded object-cover">@endif</td>
                                    <td class="py-2"><img src="{{ asset('storage/'.$v) }}" alt="" class="h-10 w-10 rounded object-cover"></td>
                                @else
                                    <td class="py-2 pr-3 text-mute">{{ $show($f, $req->original[$f] ?? $req->brand->{$f}) }}</td>
                                    <td class="py-2 font-semibold text-leafdk">{{ $show($f, $v) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex flex-wrap items-start gap-2">
                <form method="POST" action="{{ route('super.brands.changes.approve', $req) }}">@csrf<button class="rounded-lg bg-leaf px-3 py-2 text-sm font-semibold text-white">Approve</button></form>
                <form method="POST" action="{{ route('super.brands.changes.reject', $req) }}" class="flex min-w-[260px] flex-1 gap-2">@csrf
                    <input name="note" required maxlength="500" placeholder="Reason (shown to owner)" class="w-full rounded-lg border border-ink/10 px-3 py-2 text-sm">
                    <button class="shrink-0 rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white">Reject</button>
                </form>
            </div>
        </section>
    @empty
        <p class="rounded-xl border border-ink/5 bg-white p-6 text-center text-mute">No pending change requests. 🎉</p>
    @endforelse
</div>
<div class="mt-4">{{ $requests->links() }}</div>
@endsection
