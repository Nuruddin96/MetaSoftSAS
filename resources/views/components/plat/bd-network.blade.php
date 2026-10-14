{{--
    Homepage hero visual: a translucent glass map of Bangladesh with routes
    that carry a light pulse from Dhaka to district pointers. When a pulse
    reaches its pointer, the pointer flashes red twice (bd-flash in app.css).
    Geometry + timeline: App\Support\Home\BangladeshNetwork. Purely
    decorative (aria-hidden); the hero copy carries the meaning. Styles and
    reduced-motion behaviour: the "bd-" block in resources/css/app.css;
    off-screen pausing: resources/js/home.js.
--}}
@php
    $net = \App\Support\Home\BangladeshNetwork::data();
    // Per-route pulse timing: travel during the first travel_pct% of the shared cycle, then rest off the end of the route.
    $keyframes = collect($net['routes'])->map(fn ($r) => "@keyframes bd-pk{$r['i']}{0%{stroke-dashoffset:.12}{$r['travel_pct']}%,100%{stroke-dashoffset:-1}}")->implode('');
@endphp
<div {{ $attributes->merge(['class' => 'bd-viz']) }} data-bd-network aria-hidden="true">
    <div class="bd-stage" style="aspect-ratio: {{ $net['width'] }} / {{ $net['height'] }}">
        <svg class="bd-map" viewBox="0 0 {{ $net['width'] }} {{ $net['height'] }}" focusable="false">
            <style>{!! $keyframes !!}</style>
            <defs>
                <path id="bdShape" d="{{ $net['path'] }}"/>
                <clipPath id="bdClip"><use href="#bdShape"/></clipPath>
                <linearGradient id="bdGlass" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#E3F2FF" stop-opacity=".2"/>
                    <stop offset=".45" stop-color="#9DCBF2" stop-opacity=".04"/>
                    <stop offset="1" stop-color="#1769AA" stop-opacity=".16"/>
                </linearGradient>
                <linearGradient id="bdRim" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#F4FAFF"/>
                    <stop offset=".5" stop-color="#9DCBF2"/>
                    <stop offset="1" stop-color="#5FA8E6"/>
                </linearGradient>
                <radialGradient id="bdTopLight" cx=".25" cy=".15" r=".6">
                    <stop offset="0" stop-color="#fff" stop-opacity=".2"/>
                    <stop offset="1" stop-color="#fff" stop-opacity="0"/>
                </radialGradient>
                <linearGradient id="bdReflect" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#fff" stop-opacity="0"/>
                    <stop offset=".5" stop-color="#fff" stop-opacity=".16"/>
                    <stop offset="1" stop-color="#fff" stop-opacity="0"/>
                </linearGradient>
                <linearGradient id="bdSheen" x1="0" x2="1">
                    <stop offset="0" stop-color="#fff" stop-opacity="0"/>
                    <stop offset=".5" stop-color="#fff" stop-opacity=".13"/>
                    <stop offset="1" stop-color="#fff" stop-opacity="0"/>
                </linearGradient>
                <radialGradient id="bdRedGlow">
                    <stop offset="0" stop-color="#E5383B" stop-opacity=".75"/>
                    <stop offset=".45" stop-color="#E5383B" stop-opacity=".3"/>
                    <stop offset="1" stop-color="#E5383B" stop-opacity="0"/>
                </radialGradient>
                {{-- The hero's background dot field, re-drawn inside the glass slightly offset: reads as refraction. --}}
                <pattern id="bdRefract" width="30" height="30" patternUnits="userSpaceOnUse" x="5" y="4">
                    <circle cx="15" cy="15" r="1.5" fill="#BFE0FA" fill-opacity=".38"/>
                </pattern>
                <pattern id="bdGrid" width="14" height="14" patternUnits="userSpaceOnUse">
                    <path d="M14 0H0V14" fill="none" stroke="#9DCBF2" stroke-opacity=".13" stroke-width=".7"/>
                </pattern>
                <filter id="bdRimGlow" x="-10%" y="-10%" width="120%" height="120%"><feGaussianBlur stdDeviation="4"/></filter>
            </defs>

            {{-- Layered translucent edges (mostly stroke), so the slab reads as stacked glass rather than a solid. --}}
            @foreach([[16, .1], [11, .16], [6.5, .24], [3, .32]] as [$dy, $edge])
                <use href="#bdShape" transform="translate({{ $dy * .35 }} {{ $dy }})" fill="#0B4478" fill-opacity=".08" stroke="#9DCBF2" stroke-opacity="{{ $edge }}" stroke-width="1"/>
            @endforeach
            <use href="#bdShape" fill="none" stroke="#9DCBF2" stroke-width="7" opacity=".32" filter="url(#bdRimGlow)"/>
            <use href="#bdShape" fill="url(#bdGlass)" stroke="url(#bdRim)" stroke-width="1.6" stroke-linejoin="round"/>
            <g clip-path="url(#bdClip)">
                <rect width="{{ $net['width'] }}" height="{{ $net['height'] }}" fill="url(#bdRefract)"/>
                <rect width="{{ $net['width'] }}" height="{{ $net['height'] }}" fill="url(#bdGrid)"/>
                <rect width="{{ $net['width'] }}" height="{{ $net['height'] }}" fill="url(#bdTopLight)"/>
                <ellipse cx="250" cy="250" rx="420" ry="70" fill="url(#bdReflect)" transform="rotate(-28 250 250)"/>
                <use href="#bdShape" fill="none" stroke="#fff" stroke-opacity=".28" stroke-width="4"/>
                <rect class="bd-sheen" y="-100" width="150" height="1020" fill="url(#bdSheen)"/>
            </g>

            <g>@foreach($net['routes'] as $r)<path class="bd-link" d="{{ $r['d'] }}"/>@endforeach</g>
            <g>
                @foreach($net['routes'] as $r)
                    @php $anim = "animation:bd-pk{$r['i']} {$net['cycle']}s linear {$r['start']}s infinite"; @endphp
                    <path class="bd-trail" d="{{ $r['d'] }}" pathLength="1" style="{{ $anim }}"/>
                    <path class="bd-packet" d="{{ $r['d'] }}" pathLength="1" style="{{ $anim }}"/>
                @endforeach
            </g>
            <g>
                @foreach($net['nodes'] as $n)
                    <g @if($n['arrive'] !== null) style="--bd-cycle:{{ $net['cycle'] }}s;--bd-arrive:{{ $n['arrive'] }}s" @endif>
                        @if($n['arrive'] !== null)<circle class="bd-glow" cx="{{ $n['x'] }}" cy="{{ $n['y'] }}" r="16"/>@endif
                        <circle class="bd-ring" cx="{{ $n['x'] }}" cy="{{ $n['y'] }}" r="{{ $n['hub'] ? 13 : 8 }}"/>
                        <circle @class(['bd-dot', 'bd-hub' => $n['hub'], 'bd-dest' => $n['arrive'] !== null]) cx="{{ $n['x'] }}" cy="{{ $n['y'] }}" r="{{ $n['hub'] ? 6.5 : 4 }}"/>
                        @if($n['label'])<text @class(['bd-label', 'bd-hub' => $n['hub']]) x="{{ $n['x'] + ($n['hub'] ? 13 : 9) }}" y="{{ $n['y'] + 5 }}">{{ $n['name'] }}</text>@endif
                    </g>
                @endforeach
            </g>
        </svg>

        {{-- Frosted chips, placed in open sky (top right) and open sea (bottom left) so they never cover the outline or coastline. --}}
        <div class="bd-chip bd-chip-nation">
            <span class="bd-chip-icon"><x-plat.icon name="trophy" class="w-4 h-4" /></span>
            <div><p>One Nation. One Stage.</p><small>National awards · same rules</small></div>
        </div>
        <div class="bd-chip bd-chip-wide">
            <div><small>Nationwide</small><p>64 districts · one network</p></div>
        </div>
    </div>
    <div class="bd-legend">
        <span><i></i> District pointer</span>
        <span><i class="bd-red"></i> Flashes twice on arrival</span>
        <span><em></em> Route (illustrative)</span>
    </div>
</div>
