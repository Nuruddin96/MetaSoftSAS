// Recognition platform pages (brand directory/profile, voting, "List your
// brand", brand-owner dashboard): division → district selects, copy/share,
// QR code, the auto-generated share card, and the AJAX vote form. Plain DOM;
// every feature degrades to a working form/link without JS.

import qrcode from 'qrcode-generator';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

// ---- toast ----
let toastTimer;
function toast(msg) {
    let t = $('#platToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'platToast';
        t.setAttribute('role', 'status');
        t.className = 'pointer-events-none fixed inset-x-0 bottom-24 z-[90] flex justify-center px-4 lg:bottom-8';
        t.innerHTML = '<span class="rounded-full bg-night px-4 py-2.5 text-sm font-semibold text-white shadow-xl transition duration-200"></span>';
        document.body.appendChild(t);
    }
    const s = t.firstElementChild;
    s.textContent = msg;
    s.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { s.style.opacity = '0'; }, 2200);
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast('Link copied');
    } catch {
        window.prompt('Copy this link', text);
    }
}

$$('[data-copy]').forEach((b) => b.addEventListener('click', () => copyText(b.dataset.copy)));
$$('[data-share]').forEach((b) => b.addEventListener('click', async () => {
    const data = { title: b.dataset.shareTitle || document.title, text: b.dataset.shareText || '', url: b.dataset.shareUrl || location.href };
    if (navigator.share) {
        try { await navigator.share(data); } catch { /* cancelled */ }
    } else {
        copyText(data.url);
    }
}));

// ---- division → district ----
const locations = JSON.parse($('#bdLocations')?.textContent || 'null');
const divisionSel = $('[data-division-select]');
const districtSel = $('[data-district-select]');
if (locations && divisionSel && districtSel) {
    const fill = () => {
        const current = districtSel.dataset.value || districtSel.value;
        const list = locations[divisionSel.value] || [];
        districtSel.innerHTML = `<option value="">${list.length ? 'Select district' : 'Select a division first'}</option>`
            + list.map((d) => `<option value="${d}"${d === current ? ' selected' : ''}>${d}</option>`).join('');
        districtSel.disabled = !list.length;
        districtSel.dataset.value = '';
    };
    divisionSel.addEventListener('change', fill);
    fill();
}

// ---- image preview for file inputs ----
$$('input[type=file][data-preview]').forEach((input) => {
    input.addEventListener('change', () => {
        const img = $(input.dataset.preview);
        const file = input.files?.[0];
        if (!img || !file) return;
        img.src = URL.createObjectURL(file);
        img.classList.remove('hidden');
        $(input.dataset.previewHide || '#__none')?.classList.add('hidden');
    });
});

// ---- password visibility ----
$$('[data-toggle-password]').forEach((b) => b.addEventListener('click', () => {
    const input = $(b.dataset.togglePassword);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    b.textContent = input.type === 'password' ? 'Show' : 'Hide';
}));

// ---- QR code ----
function qrFor(url) {
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    return qr;
}
$$('[data-qr]').forEach((el) => {
    const qr = qrFor(el.dataset.qr);
    el.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    const svg = el.querySelector('svg');
    if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code for your voting link'); svg.classList.add('h-full', 'w-full'); }
});
$$('[data-qr-download]').forEach((b) => b.addEventListener('click', () => {
    const qr = qrFor(b.dataset.qrDownload);
    const n = qr.getModuleCount();
    const scale = 12;
    const margin = 4;
    const size = (n + margin * 2) * scale;
    const c = document.createElement('canvas');
    c.width = c.height = size;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#0A1428';
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (qr.isDark(r, col)) ctx.fillRect((col + margin) * scale, (r + margin) * scale, scale, scale);
    download(c, b.dataset.filename || 'voting-qr.png');
}));

function download(canvas, filename) {
    const a = document.createElement('a');
    a.download = filename;
    a.href = canvas.toDataURL('image/png');
    document.body.appendChild(a);
    a.click();
    a.remove();
}

// ---- share card (1080×1080 poster, no design work needed) ----
function loadImage(src) {
    return new Promise((resolve) => {
        if (!src) return resolve(null);
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => resolve(null);
        img.src = src;
    });
}
function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}
function wrapText(ctx, text, x, y, maxW, lineH, maxLines) {
    const words = String(text).split(/\s+/);
    let line = '';
    let lines = 0;
    for (let i = 0; i < words.length; i++) {
        const test = line ? `${line} ${words[i]}` : words[i];
        if (ctx.measureText(test).width > maxW && line) {
            if (++lines >= maxLines) { ctx.fillText(`${line}…`, x, y); return y; }
            ctx.fillText(line, x, y);
            line = words[i];
            y += lineH;
        } else {
            line = test;
        }
    }
    ctx.fillText(line, x, y);
    return y;
}
async function drawShareCard(canvas, d) {
    await document.fonts?.ready;
    const ctx = canvas.getContext('2d');
    const S = 1080;
    canvas.width = canvas.height = S;
    const font = '"Plus Jakarta Sans", "Hind Siliguri", system-ui, sans-serif';

    const g = ctx.createLinearGradient(0, 0, S, S);
    g.addColorStop(0, '#0A1428');
    g.addColorStop(0.55, '#0E2350');
    g.addColorStop(1, '#00513C');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, S, S);
    ctx.fillStyle = 'rgba(229,56,59,0.22)';
    ctx.beginPath(); ctx.arc(S - 120, 170, 260, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.05)';
    for (let x = 30; x < S; x += 36) for (let y = 30; y < S; y += 36) ctx.fillRect(x, y, 2, 2);

    ctx.fillStyle = '#E9C46A';
    ctx.font = `800 30px ${font}`;
    // Award name can be long ("Bangladesh Brand & Entrepreneur Awards 2026"): wrap to two lines.
    wrapText(ctx, (d.campaign || 'Bangladesh Brand & Entrepreneur Awards 2026').toUpperCase(), 90, 120, S - 420, 38, 2);

    // logo tile
    roundRect(ctx, 90, 210, 240, 240, 48);
    ctx.fillStyle = '#fff';
    ctx.fill();
    const logo = await loadImage(d.logo);
    ctx.save();
    roundRect(ctx, 106, 226, 208, 208, 38);
    ctx.clip();
    if (logo) {
        const r = Math.max(208 / logo.width, 208 / logo.height);
        ctx.drawImage(logo, 106 + (208 - logo.width * r) / 2, 226 + (208 - logo.height * r) / 2, logo.width * r, logo.height * r);
    } else {
        const lg = ctx.createLinearGradient(106, 226, 314, 434);
        lg.addColorStop(0, '#128155'); lg.addColorStop(1, '#0C5C3C');
        ctx.fillStyle = lg; ctx.fillRect(106, 226, 208, 208);
        ctx.fillStyle = '#fff'; ctx.font = `800 84px ${font}`; ctx.textAlign = 'center';
        ctx.fillText(d.initials || 'B', 210, 360);
        ctx.textAlign = 'left';
    }
    ctx.restore();

    ctx.fillStyle = '#fff';
    ctx.font = `800 76px ${font}`;
    const yEnd = wrapText(ctx, d.name, 90, 560, 900, 84, 2);
    ctx.fillStyle = 'rgba(255,255,255,0.75)';
    ctx.font = `600 36px ${font}`;
    // "Nominee" only when the brand really is in voting; otherwise just its category.
    const sub = d.nominee === '1' ? ['Nominee', d.category].filter(Boolean).join(' · ') : (d.category || 'Brand');
    ctx.fillText(sub, 90, yEnd + 66);

    // CTA pill
    ctx.font = `800 44px ${font}`;
    const cta = d.cta || 'Vote Now';
    const w = ctx.measureText(cta).width + 120;
    roundRect(ctx, 90, yEnd + 120, w, 96, 48);
    ctx.fillStyle = '#E9C46A';
    ctx.fill();
    ctx.fillStyle = '#0A1428';
    ctx.fillText(cta, 150, yEnd + 184);

    ctx.fillStyle = 'rgba(255,255,255,0.9)';
    ctx.font = `700 32px ${font}`;
    ctx.fillText(d.url.replace(/^https?:\/\//, ''), 90, S - 150);

    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.fillRect(90, S - 115, S - 180, 2);
    ctx.fillStyle = '#fff';
    ctx.font = `800 34px ${font}`;
    ctx.fillText('MetaSoft BD', 90, S - 60);
    ctx.fillStyle = 'rgba(255,255,255,0.6)';
    ctx.font = `600 26px ${font}`;
    ctx.fillText('Brand & Entrepreneur Network', 310, S - 62);
}
$$('[data-share-card]').forEach(async (canvas) => {
    const d = { ...canvas.dataset };
    await drawShareCard(canvas, d);
    $$(`[data-share-card-download="${canvas.id}"]`).forEach((b) => b.addEventListener('click', () => download(canvas, b.dataset.filename || 'vote-card.png')));
    $$(`[data-share-card-share="${canvas.id}"]`).forEach((b) => b.addEventListener('click', () => {
        canvas.toBlob(async (blob) => {
            const file = new File([blob], 'vote-card.png', { type: 'image/png' });
            if (navigator.canShare?.({ files: [file] })) {
                try { await navigator.share({ files: [file], title: d.name, text: `${d.cta || 'Vote Now'}: ${d.url}` }); } catch { /* cancelled */ }
            } else {
                download(canvas, 'vote-card.png');
            }
        });
    }));
});

// ---- vote form (AJAX with graceful fallback to a normal POST) ----
$$('form[data-vote-form]').forEach((form) => {
    const phone = $('input[name=phone]', form);
    const msg = $('[data-vote-msg]', form);
    const btn = $('button[type=submit]', form);
    const show = (text, ok) => {
        if (!msg) return;
        msg.textContent = text;
        msg.className = `mt-3 rounded-xl px-3.5 py-3 text-sm font-semibold ${ok ? 'bg-mint text-leafdk' : 'bg-rose-50 text-rose-700'}`;
    };
    phone?.addEventListener('input', () => { phone.value = phone.value.replace(/[^\d+]/g, '').slice(0, 14); });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const digits = phone.value.replace(/\D/g, '').replace(/^880/, '0');
        if (!/^0?1[3-9]\d{8}$/.test(digits)) { show('Enter a valid Bangladeshi mobile number (e.g. 01712345678).', false); phone.focus(); return; }
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = 'Submitting…';
        try {
            const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) {
                show(data.message, true);
                form.querySelector('[data-vote-fields]')?.classList.add('hidden');
                if (data.votes !== null && data.votes !== undefined) {
                    $$(`[data-votes-for="${form.dataset.entry}"]`).forEach((el) => { el.textContent = Number(data.votes).toLocaleString(); });
                    $$(`[data-rank-for="${form.dataset.entry}"]`).forEach((el) => { el.textContent = `#${data.rank}`; });
                }
                $(`[data-after-vote="${form.dataset.entry}"]`)?.classList.remove('hidden');
            } else if (res.status === 429) {
                show('Too many attempts. Please wait a minute and try again.', false);
            } else {
                show(data.message || Object.values(data.errors || {})[0]?.[0] || 'Your vote could not be submitted. Please try again.', false);
            }
        } catch {
            show('Network problem — please check your connection and try again.', false);
        } finally {
            btn.disabled = false;
            btn.textContent = label;
        }
    });
});

// ---- owner dashboard mobile menu ----
const ownerMenu = $('#ownerMenu');
$('#ownerMenuOpen')?.addEventListener('click', () => ownerMenu?.classList.remove('hidden'));
$$('[data-close-owner-menu]').forEach((el) => el.addEventListener('click', () => ownerMenu?.classList.add('hidden')));
