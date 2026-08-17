@extends('layouts.store')

@section('title', $page->title . ' — ' . $tenant->store_name)

@section('content')
<article class="max-w-2xl mx-auto">
    <h1 class="font-disp font-bold text-2xl md:text-3xl mb-6">{{ $page->page_header ?: $page->title }}</h1>
    <div class="prose text-sm leading-relaxed text-ink/90 whitespace-pre-line">{!! $page->content !!}</div>
</article>
@endsection
