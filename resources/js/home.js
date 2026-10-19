// Central homepage (metasoftbd.com) interactions: mobile menu, tabs,
// countdown, profile quick view, vote dialog, sharing and scroll-spy.
// Plain DOM, no framework — the page is server-rendered and every feature
// degrades to a working link/anchor without JS.

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

let lastFocus = null;
function openLayer(el, focusSel) {
    lastFocus = document.activeElement;
    el.classList.remove('hidden');
    if (el.id === 'voteModal') el.classList.add('flex');
    document.documentElement.style.overflow = 'hidden';
    requestAnimationFrame(() => (focusSel ? $(focusSel, el) : $('button, a, input', el))?.focus());
}
function closeLayer(el) {
    if (!el || el.classList.contains('hidden')) return;
    el.classList.add('hidden');
    el.classList.remove('flex');
    document.documentElement.style.overflow = '';
    lastFocus?.focus?.();
}

// ---- toast ----
let toastTimer;
function toast(msg) {
    const t = $('#toast span');
    if (!t) return;
    t.textContent = msg;
    t.classList.remove('opacity-0', 'translate-y-3');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.add('opacity-0', 'translate-y-3'), 2200);
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast('Link copied');
    } catch {
        window.prompt('Copy this link', text);
    }
}

async function share(title, url) {
    if (navigator.share) {
        try { await navigator.share({ title, url }); } catch { /* user cancelled */ }
        return;
    }
    copyText(url);
}

// ---- mobile menu ----
const menu = $('#mobileMenu');
const menuBtn = $('#menuOpen');
menuBtn?.addEventListener('click', () => { menuBtn.setAttribute('aria-expanded', 'true'); openLayer(menu); });
$$('[data-close-menu]').forEach((el) => el.addEventListener('click', () => { menuBtn?.setAttribute('aria-expanded', 'false'); closeLayer(menu); }));

// ---- tabs (voting categories, ranking types) ----
$$('[data-tabs]').forEach((list) => {
    const group = list.dataset.tabs;
    const tabs = $$('[role="tab"]', list);
    const select = (tab) => {
        tabs.forEach((t) => {
            const on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            $(`[data-panel="${group}:${t.dataset.tab}"]`)?.classList.toggle('hidden', !on);
        });
    };
    tabs.forEach((t, i) => {
        t.tabIndex = t.getAttribute('aria-selected') === 'true' ? 0 : -1;
        t.addEventListener('click', () => select(t));
        t.addEventListener('keydown', (e) => {
            const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!d) return;
            e.preventDefault();
            const next = tabs[(i + d + tabs.length) % tabs.length];
            next.focus();
            select(next);
        });
    });
});

// ---- countdown ----
$$('[data-countdown]').forEach((el) => {
    const end = new Date(el.dataset.countdown).getTime();
    const units = Object.fromEntries($$('[data-unit]', el).map((u) => [u.dataset.unit, u]));
    const tick = () => {
        let s = Math.max(0, Math.floor((end - Date.now()) / 1000));
        const vals = { days: Math.floor(s / 86400), hrs: Math.floor((s % 86400) / 3600), min: Math.floor((s % 3600) / 60), sec: s % 60 };
        for (const [k, v] of Object.entries(vals)) if (units[k]) units[k].textContent = String(v).padStart(2, '0');
    };
    tick();
    setInterval(tick, 1000);
});

// ---- copy / share buttons ----
$$('[data-copy]').forEach((b) => b.addEventListener('click', () => copyText(b.dataset.copy)));

// ---- "Find Brands" hero tile: scroll to and focus the hero search ----
$$('[data-focus]').forEach((a) => a.addEventListener('click', (e) => {
    const field = $(a.dataset.focus);
    if (!field) return;
    e.preventDefault();
    field.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    field.focus({ preventScroll: true });
}));
$$('[data-share]').forEach((b) => b.addEventListener('click', () => share(b.dataset.shareTitle || document.title, b.dataset.shareUrl || location.href)));

// ---- entrepreneur profile dialog (brands have their own /brand/{slug} page) ----
const profiles = JSON.parse($('#profilesData')?.textContent || '{}');
const drawer = $('#profileDrawer');
const badgeClass = {
    winner: 'bg-[#E9C46A]/25 text-[#9A6B0A]', jury: 'bg-indigo-50 text-indigo-700', people: 'bg-rose-50 text-rose-700',
    finalist: 'bg-[#E8F5EF] text-[#0C5C3C]', verified: 'bg-sky-50 text-sky-700',
};
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const profileLink = (p) => p.profile_url || `${location.origin}/?q=${encodeURIComponent(p.name)}#results`;
const seal = '<img src="/images/badges/metasoft-verified.png" alt="MetaSoft BD verified" class="inline-block h-5 w-5">';
const safeUrl = (u) => (/^https?:\/\//i.test(u || '') ? u : null);

// Logo image when the profile has one (demo artwork or a real upload), otherwise initials.
function setLogo(el, src, initials) {
    el.textContent = '';
    el.style.overflow = 'hidden';
    if (src) {
        const img = document.createElement('img');
        img.src = src;
        img.alt = '';
        img.className = 'h-full w-full object-cover';
        el.appendChild(img);
    } else {
        el.textContent = initials;
    }
}

// Show an optional section only when it has content (so no brand ever shows another's leftovers).
function section(name, html) {
    const s = $(`[data-pd-section="${name}"]`, drawer);
    if (!s) return;
    s.classList.toggle('hidden', !html);
    const body = s.lastElementChild;
    body.innerHTML = html || '';
}

function openProfile(key) {
    const p = profiles[key];
    if (!p || !drawer) return;
    const grad = `linear-gradient(135deg,${p.from},${p.to})`;
    $('#pdCover').style.background = p.cover ? `center / cover no-repeat url("${encodeURI(p.cover)}"), ${grad}` : grad;
    const logo = $('#pdLogo');
    logo.style.background = grad;
    logo.style.borderRadius = p.type === 'person' ? '9999px' : '20px';
    setLogo(logo, p.logo, p.initials);

    // The official seal only for profiles the backend marks verified.
    const verified = p.verified === true;
    $('#pdName').innerHTML = esc(p.name) + (verified ? ` ${seal}` : '');
    $('#pdSubtitle').textContent = p.subtitle || '';

    const badges = p.badges?.length ? p.badges : (verified ? [{ label: 'Verified business', type: 'verified' }] : []);
    $('#pdBadges').innerHTML = badges
        .map((b) => `<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClass[b.type] || badgeClass.finalist}">${esc(b.label)}</span>`).join('');
    $('#pdFacts').innerHTML = (p.facts || [])
        .map(([k, v]) => `<div class="min-w-0 rounded-xl bg-[#F4F6FA] p-3"><dt class="text-[11px] text-[#5A6478]">${esc(k)}</dt><dd class="truncate text-sm font-bold" title="${esc(v)}">${esc(v)}</dd></div>`).join('');

    section('desc', p.description ? esc(p.description) : '');
    section('products', p.products ? esc(p.products) : '');
    section('gallery', (p.gallery || []).filter(safeUrl).map((src, i) =>
        `<a href="${esc(src)}" target="_blank" rel="noopener" class="block aspect-square overflow-hidden rounded-xl bg-[#F4F6FA]"><img src="${esc(src)}" alt="${esc(p.name)} photo ${i + 1}" loading="lazy" decoding="async" class="h-full w-full object-cover"></a>`).join(''));
    section('links', (p.links || []).filter((l) => safeUrl(l.url)).map((l) =>
        `<a href="${esc(l.url)}" target="_blank" rel="noopener nofollow ugc" class="inline-flex min-h-10 items-center rounded-xl border border-[#E6E9F0] px-3 text-sm font-semibold hover:border-[#12A06E]">${esc(l.label)}</a>`).join(''));
    section('contact', (p.contact || []).map((c) =>
        `<div class="flex gap-2 text-sm"><dt class="w-16 shrink-0 text-[#5A6478]">${esc(c.label)}</dt><dd class="min-w-0 break-words font-semibold">${safeUrl(c.href) || /^(tel|mailto):/.test(c.href || '') ? `<a href="${esc(c.href)}" class="hover:underline">${esc(c.value)}</a>` : esc(c.value)}</dd></div>`).join(''));

    const full = $('#pdProfile');
    full.classList.toggle('hidden', !p.profile_url);
    full.classList.toggle('inline-flex', !!p.profile_url);
    full.href = p.profile_url || '#';

    const link = profileLink(p);
    $('#pdShare').onclick = () => share(`${p.name} on MetaSoft BD`, link);
    $('#pdWhatsapp').href = `https://wa.me/?text=${encodeURIComponent(`${p.name} on MetaSoft BD: ${link}`)}`;
    const scroller = $('[data-drawer-scroll]', drawer);
    if (scroller) scroller.scrollTop = 0;
    openLayer(drawer, '[data-close-drawer]:not(.absolute.inset-0)');
}
document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-profile]');
    if (t) { e.preventDefault(); openProfile(t.dataset.profile); }
});
$$('[data-close-drawer]').forEach((el) => el.addEventListener('click', () => closeLayer(drawer)));

// ---- vote dialog ----
const vm = $('#voteModal');
let current = null;
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-vote]');
    if (!b || !vm) return;
    current = { ...b.dataset };
    $('#vmCategory').textContent = current.category;
    $('#vmTitle').textContent = current.name;
    const logo = $('#vmLogo');
    logo.style.background = `linear-gradient(135deg,${current.from},${current.to})`;
    setLogo(logo, current.logo, current.initials);
    $('#voteForm').classList.remove('hidden');
    $('#voteDone').classList.add('hidden');
    $('#vmError').classList.add('hidden');
    $('#vmPhone').value = '';
    openLayer(vm, '#vmPhone');
});
$$('[data-close-vote]').forEach((el) => el.addEventListener('click', () => closeLayer(vm)));
$('#vmPhone')?.addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').replace(/^0/, '').slice(0, 10); });
$('#voteForm')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const phone = $('#vmPhone').value;
    if (!/^1[3-9]\d{8}$/.test(phone)) { $('#vmError').classList.remove('hidden'); $('#vmPhone').focus(); return; }
    $('#vmError').classList.add('hidden');
    $('#voteForm').classList.add('hidden');
    $('#voteDone').classList.remove('hidden');
    $('#vmDoneText').textContent = window.__platformPreview
        ? 'This is a preview — live voting with OTP verification opens with the Awards 2026 launch, so this vote was not recorded yet. Share the page so friends are ready to vote!'
        : `We sent a code to +880${phone}. Enter it to confirm your vote for ${current.name}.`;
    const url = `${location.origin}/?q=${encodeURIComponent(current.name)}#results`;
    const msg = `Vote for ${current.name} in ${current.category} on MetaSoft BD: ${url}`;
    $('#vmShareWa').href = `https://wa.me/?text=${encodeURIComponent(msg)}`;
    $('#vmShareFb').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
});

// ---- Escape closes any open layer ----
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    [vm, drawer, menu].forEach(closeLayer);
});

// ---- keep keyboard focus inside an open dialog/menu ----
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return;
    const layer = [vm, drawer, menu].find((l) => l && !l.classList.contains('hidden'));
    if (!layer) return;
    const items = $$('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])', layer)
        .filter((el) => el.offsetParent !== null && !el.matches('.absolute.inset-0'));
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && (document.activeElement === first || !layer.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (document.activeElement === last || !layer.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
});

// ---- "Vote for your favorite brand": a picked nominee goes straight to its /vote/{slug} page ----
// Anything else submits normally to the campaign page, which lists every nominee.
$$('[data-vote-finder]').forEach((form) => form.addEventListener('submit', (e) => {
    const input = $('input', form);
    const name = input.value.trim().toLowerCase();
    const option = $$(`#${input.getAttribute('list')} option`).find((o) => o.value.toLowerCase() === name);
    if (option?.dataset.url) {
        e.preventDefault();
        location.href = option.dataset.url;
    }
}));

// ---- scroll-spy for the desktop nav ----
const navLinks = $$('[data-nav]');
const sections = navLinks.map((a) => document.getElementById(a.dataset.nav)).filter(Boolean);
if ('IntersectionObserver' in window && sections.length) {
    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (!en.isIntersecting) return;
            navLinks.forEach((a) => a.setAttribute('aria-current', a.dataset.nav === en.target.id ? 'true' : 'false'));
        });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach((s) => io.observe(s));
}

// ---- hero glass map: pause its animations while it is off-screen ----
// Everything pauses together, so the pulses and the red pointer flashes stay in sync when it resumes.
const network = $('[data-bd-network]');
if (network && 'IntersectionObserver' in window) {
    new IntersectionObserver(([en]) => network.classList.toggle('bd-paused', !en.isIntersecting)).observe(network);
}

// ---- slow continuous photo carousels ([data-marquee="px/s"]) ----
// The row scrolls natively (touch, trackpad, wheel) and is advanced by script. Its items are repeated
// as aria-hidden + inert copies until the loop is seamless. Holding, dragging, hovering or focusing
// pauses it; it eases back in after release. Nothing moves under prefers-reduced-motion.
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
$$('[data-marquee]').forEach((row) => {
    const items = [...row.children];
    const speed = parseFloat(row.dataset.marquee) || 14;
    const ctrl = $(`[data-marquee-controls="${row.id}"]`);
    const pauseBtn = ctrl && $('[data-mq="pause"]', ctrl);
    let set = 0, pos = 0, v = 0, last = 0, idleUntil = 0, held = false, hover = false, focus = false, paused = false, seen = true, drag = null;

    const copy = () => items.forEach((it) => {
        const c = it.cloneNode(true);
        c.setAttribute('aria-hidden', 'true');
        c.inert = true;
        c.removeAttribute('id');
        $$('[id]', c).forEach((n) => n.removeAttribute('id'));
        row.appendChild(c);
    });
    const fill = () => {
        if (row.children.length === items.length) copy();
        set = row.children[items.length].offsetLeft - items[0].offsetLeft;
        while (row.scrollWidth - set < row.clientWidth + 4) copy();
    };
    const wrap = () => { if (pos >= set) pos -= set; else if (pos < 0) pos += set; };
    const rest = (ms) => { idleUntil = Math.max(idleUntil, performance.now() + ms); };
    if (!items.length) return;
    fill();
    addEventListener('resize', fill);

    row.addEventListener('scroll', () => {
        if (Math.abs(row.scrollLeft - pos) < 2) return; // our own step
        pos = row.scrollLeft;
        rest(1400);
        if (pos >= set) { pos -= set; row.scrollLeft = pos; } else if (pos <= 0) { pos += set; row.scrollLeft = pos; }
    }, { passive: true });
    row.addEventListener('pointerdown', (e) => {
        held = true; v = 0;
        if (e.pointerType === 'mouse' && e.button === 0) drag = { x: e.clientX, s: row.scrollLeft, moved: false };
    });
    row.addEventListener('pointermove', (e) => {
        if (!drag) return;
        const dx = e.clientX - drag.x;
        if (!drag.moved && Math.abs(dx) > 4) { drag.moved = true; row.classList.add('is-dragging'); row.setPointerCapture(e.pointerId); }
        if (drag.moved) { pos = drag.s - dx; wrap(); row.scrollLeft = pos; }
    });
    const release = () => {
        held = false;
        rest(1200);
        if (!drag) return;
        const moved = drag.moved;
        drag = null;
        row.classList.remove('is-dragging');
        if (moved) row.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); }, { capture: true, once: true });
    };
    ['pointerup', 'pointercancel'].forEach((t) => row.addEventListener(t, release));
    row.addEventListener('touchstart', () => { held = true; }, { passive: true });
    row.addEventListener('touchend', release, { passive: true });
    row.addEventListener('wheel', () => rest(1400), { passive: true });
    row.addEventListener('mouseenter', () => { hover = true; });
    row.addEventListener('mouseleave', () => { hover = false; if (drag) release(); });
    row.addEventListener('focusin', () => { focus = true; });
    row.addEventListener('focusout', () => { focus = false; rest(800); });
    if ('IntersectionObserver' in window) new IntersectionObserver(([en]) => { seen = en.isIntersecting; }).observe(row);

    if (ctrl) {
        $$('[data-mq="prev"],[data-mq="next"]', ctrl).forEach((b) => b.addEventListener('click', () => {
            const dir = b.dataset.mq === 'next' ? 1 : -1;
            const step = items[0].offsetWidth + (parseFloat(getComputedStyle(row).columnGap) || 0);
            if (dir < 0 && pos - step < 0) { pos += set; row.scrollLeft = pos; }
            if (dir > 0 && pos + step > row.scrollWidth - row.clientWidth) { pos -= set; row.scrollLeft = pos; }
            rest(2600);
            row.scrollBy({ left: dir * step, behavior: reduceMotion.matches ? 'auto' : 'smooth' });
        }));
        pauseBtn?.addEventListener('click', () => {
            paused = !paused;
            pauseBtn.setAttribute('aria-pressed', paused);
            $('[data-label]', pauseBtn).textContent = paused ? 'Play' : 'Pause';
        });
    }
    const sync = () => { if (pauseBtn) pauseBtn.hidden = reduceMotion.matches; };
    sync();
    reduceMotion.addEventListener?.('change', sync);

    requestAnimationFrame(function tick(t) {
        const dt = last ? Math.min(0.05, (t - last) / 1000) : 0;
        last = t;
        const want = (held || hover || focus || paused || !seen || reduceMotion.matches || t < idleUntil || document.hidden) ? 0 : speed;
        v += (want - v) * Math.min(1, dt * (want > v ? 0.7 : 8)); // eases in over ~2s, stops almost at once
        if (v > 0.05 && !held) { pos += v * dt; wrap(); row.scrollLeft = pos; }
        requestAnimationFrame(tick);
    });
});

// ---- land on the requested section (e.g. /?q=Dhaka#results) ----
// Smooth scrolling + late web-font layout shifts can leave the browser's
// own hash jump short of the target, so re-apply it once layout settles.
if (location.hash.length > 1) {
    const target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (target) window.addEventListener('load', () => target.scrollIntoView({ behavior: 'auto', block: 'start' }), { once: true });
}
