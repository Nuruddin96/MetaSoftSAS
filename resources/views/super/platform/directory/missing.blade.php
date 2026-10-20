@extends('layouts.super')

@section('title', 'Directory — Brand platform')

@section('content')
@include('super.platform._tabs')
<div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
    <b>Directory not set up yet.</b> Import <code>database/sql/chunk70.sql</code>, then run
    <code>php artisan platform:directory-import</code> (dry run) and <code>--apply</code>.
</div>
@endsection
