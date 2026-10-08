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
// Greedy word wrap → array of lines (the last line ends with … if text was cut).
function wrapLines(ctx, text, maxW, maxLines) {
    const words = String(text || '').split(/\s+/).filter(Boolean);
    const out = [];
    let line = '';
    for (let i = 0; i < words.length; i++) {
        const test = line ? `${line} ${words[i]}` : words[i];
        if (ctx.measureText(test).width > maxW && line) {
            if (out.length === maxLines - 1) {
                let cut = line;
                while (cut && ctx.measureText(`${cut}…`).width > maxW) cut = cut.slice(0, -1);
                out.push(`${cut}…`);
                return out;
            }
            out.push(line);
            line = words[i];
        } else {
            line = test;
        }
    }
    if (line) out.push(line);
    return out;
}
// Shrink a single line's font until it fits maxW.
function fitFont(ctx, text, weight, size, min, maxW, family) {
    for (; size > min; size -= 2) {
        ctx.font = `${weight} ${size}px ${family}`;
        if (ctx.measureText(text).width <= maxW) return size;
    }
    ctx.font = `${weight} ${min}px ${family}`;
    return min;
}
// Draw an image filling (cover) or fitting inside (contain) a box.
function drawImageIn(ctx, img, x, y, w, h, mode) {
    const r = (mode === 'contain' ? Math.min : Math.max)(w / img.width, h / img.height);
    ctx.drawImage(img, x + (w - img.width * r) / 2, y + (h - img.height * r) / 2, img.width * r, img.height * r);
}
function spaced(ctx, px) {
    if ('letterSpacing' in ctx) ctx.letterSpacing = `${px}px`;
}

/*
 * 1080×1080 voting creative: the award programme up top, the brand as the
 * hero (its own cover photo, logo, name, category and — only for verified
 * brands — the official MetaSoft BD badge), then the call to vote, the
 * Vote Now / Free Join Now CTAs, and the real voting URL + QR in the footer.
 */
async function drawShareCard(canvas, d) {
    // Make sure every weight the card uses is loaded before drawing.
    await Promise.all(['500', '600', '700', '800'].map((w) => document.fonts?.load(`${w} 40px "Plus Jakarta Sans"`).catch(() => null)));
    await document.fonts?.ready;
    const ctx = canvas.getContext('2d');
    const S = 1080;
    const M = 72;
    const W = S - M * 2;
    canvas.width = canvas.height = S;
    const font = '"Plus Jakarta Sans", system-ui, sans-serif';
    const gold = '#E9C46A';
    const [logo, cover, badge] = await Promise.all([loadImage(d.logo), loadImage(d.cover), d.verified === '1' ? loadImage(d.badge) : null]);

    // ---- backdrop ----
    const g = ctx.createLinearGradient(0, 0, S, S);
    g.addColorStop(0, '#060E1F');
    g.addColorStop(0.55, '#0B1E46');
    g.addColorStop(1, '#00412F');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, S, S);
    const glow = ctx.createRadialGradient(S - 80, 60, 0, S - 80, 60, 520);
    glow.addColorStop(0, 'rgba(233,196,106,0.22)');
    glow.addColorStop(1, 'rgba(233,196,106,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, S, S);
    ctx.fillStyle = 'rgba(255,255,255,0.04)';
    for (let x = 40; x < S; x += 32) for (let y = 40; y < S; y += 32) ctx.fillRect(x, y, 2, 2);
    roundRect(ctx, 28, 28, S - 56, S - 56, 40);
    ctx.strokeStyle = 'rgba(233,196,106,0.4)';
    ctx.lineWidth = 2;
    ctx.stroke();

    // ---- header: kicker, MetaSoft BD wordmark, award programme ----
    ctx.fillStyle = gold;
    ctx.fillRect(M, 98, 36, 4);
    ctx.font = `800 22px ${font}`;
    spaced(ctx, 4);
    ctx.fillText(d.nominee === '1' ? 'OFFICIAL NOMINEE' : 'PARTICIPATING BRAND', M + 52, 108);
    spaced(ctx, 0);
    ctx.textAlign = 'right';
    ctx.fillStyle = '#fff';
    ctx.font = `800 26px ${font}`;
    ctx.fillText('MetaSoft BD', S - M, 108);
    ctx.textAlign = 'left';

    ctx.font = `800 44px ${font}`;
    let prog = wrapLines(ctx, d.campaign || 'Bangladesh Brand & Entrepreneur Awards 2026', W, 2);
    // Two lines: balance them so no word is left alone on the second line.
    if (prog.length === 2 && !prog[1].endsWith('…')) {
        const words = prog.join(' ').split(' ');
        let best = prog;
        let bestW = Infinity;
        for (let i = 1; i < words.length; i++) {
            const pair = [words.slice(0, i).join(' '), words.slice(i).join(' ')];
            const w = Math.max(...pair.map((l) => ctx.measureText(l).width));
            if (w <= W && w < bestW) { best = pair; bestW = w; }
        }
        prog = best;
    }
    ctx.fillStyle = '#fff';
    prog.forEach((l, i) => ctx.fillText(l, M, (prog.length === 1 ? 196 : 168) + i * 52));

    // ---- hero: the brand ----
    const hy = 248;
    const hh = 320;
    ctx.save();
    roundRect(ctx, M, hy, W, hh, 32);
    ctx.clip();
    if (cover) {
        drawImageIn(ctx, cover, M, hy, W, hh, 'cover');
    } else {
        const cg = ctx.createLinearGradient(M, hy, M + W, hy + hh);
        cg.addColorStop(0, '#14336B');
        cg.addColorStop(1, '#0B6B4F');
        ctx.fillStyle = cg;
        ctx.fillRect(M, hy, W, hh);
        ctx.fillStyle = 'rgba(255,255,255,0.06)';
        ctx.beginPath(); ctx.arc(M + W - 60, hy + 40, 220, 0, Math.PI * 2); ctx.fill();
    }
    const shade = ctx.createLinearGradient(M, 0, M + W, 0);
    shade.addColorStop(0, 'rgba(5,11,26,0.92)');
    shade.addColorStop(0.7, 'rgba(5,11,26,0.8)');
    shade.addColorStop(1, 'rgba(5,11,26,0.3)');
    ctx.fillStyle = shade;
    ctx.fillRect(M, hy, W, hh);
    ctx.restore();
    roundRect(ctx, M, hy, W, hh, 32);
    ctx.strokeStyle = 'rgba(255,255,255,0.14)';
    ctx.stroke();

    const tile = 184;
    const tx = M + 40;
    const ty = hy + (hh - tile) / 2;
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.35)';
    ctx.shadowBlur = 30;
    roundRect(ctx, tx, ty, tile, tile, 36);
    ctx.fillStyle = '#fff';
    ctx.fill();
    ctx.restore();
    ctx.save();
    roundRect(ctx, tx + 10, ty + 10, tile - 20, tile - 20, 28);
    ctx.clip();
    if (logo) {
        drawImageIn(ctx, logo, tx + 10, ty + 10, tile - 20, tile - 20, 'contain');
    } else {
        const lg = ctx.createLinearGradient(tx, ty, tx + tile, ty + tile);
        lg.addColorStop(0, '#128155'); lg.addColorStop(1, '#0C5C3C');
        ctx.fillStyle = lg; ctx.fillRect(tx, ty, tile, tile);
        ctx.fillStyle = '#fff'; ctx.font = `800 72px ${font}`; ctx.textAlign = 'center';
        ctx.fillText(d.initials || 'B', tx + tile / 2, ty + tile / 2 + 26);
        ctx.textAlign = 'left';
    }
    ctx.restore();

    // Name at the largest size that fits two lines, the badge right after its last word.
    const nx = tx + tile + 40;
    const nw = M + W - 40 - nx;
    let size = 72;
    let name;
    for (; ; size -= 4) {
        ctx.font = `800 ${size}px ${font}`;
        name = wrapLines(ctx, d.name, nw - (badge ? size * 0.95 : 0), 2);
        if (size <= 44 || !name[name.length - 1].endsWith('…')) break;
    }
    const lh = Math.round(size * 1.1);
    const pillH = 48;
    const block = (name.length - 1) * lh + size * 0.75 + 26 + pillH;
    let y = hy + (hh - block) / 2 + size * 0.75;
    ctx.fillStyle = '#fff';
    name.forEach((l, i) => {
        ctx.fillText(l, nx, y);
        if (badge && i === name.length - 1) {
            const b = Math.round(size * 0.8);
            ctx.drawImage(badge, nx + ctx.measureText(l).width + 14, y - size * 0.7, b, b);
        }
        if (i < name.length - 1) y += lh;
    });

    ctx.font = `700 24px ${font}`;
    const cat = wrapLines(ctx, d.category || 'Brand', nw - 44, 1)[0];
    const pw = ctx.measureText(cat).width + 44;
    const py = y + 26;
    roundRect(ctx, nx, py, pw, pillH, pillH / 2);
    ctx.fillStyle = 'rgba(233,196,106,0.18)';
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(233,196,106,0.55)';
    ctx.stroke();
    ctx.fillStyle = gold;
    ctx.fillText(cat, nx + 22, py + 32);

    // ---- the ask ----
    ctx.font = `800 54px ${font}`;
    const lead = 'Support Your ';
    ctx.fillStyle = '#fff';
    ctx.fillText(lead, M, 646);
    ctx.fillStyle = gold;
    ctx.fillText('Favourite Brand', M + ctx.measureText(lead).width, 646);

    ctx.font = `500 27px ${font}`;
    ctx.fillStyle = 'rgba(255,255,255,0.8)';
    wrapLines(ctx, 'Join thousands of brands across Bangladesh and earn recognition for your business through public voting.', W, 2)
        .forEach((l, i) => ctx.fillText(l, M, 694 + i * 38));

    // Vote Now (primary) + Free Join Now (secondary)
    const by = 762;
    ctx.font = `800 46px ${font}`;
    const cta = d.cta || 'Vote Now';
    const vw = ctx.measureText(cta).width + 150;
    ctx.save();
    ctx.shadowColor = 'rgba(233,196,106,0.35)';
    ctx.shadowBlur = 28;
    const vg = ctx.createLinearGradient(0, by, 0, by + 100);
    vg.addColorStop(0, '#F6D98A');
    vg.addColorStop(1, '#DDB04A');
    roundRect(ctx, M, by, vw, 100, 50);
    ctx.fillStyle = vg;
    ctx.fill();
    ctx.restore();
    ctx.fillStyle = '#0A1428';
    ctx.fillText(cta, M + 52, by + 66);
    const ax = M + vw - 70;
    ctx.strokeStyle = '#0A1428';
    ctx.lineWidth = 6;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(ax, by + 50); ctx.lineTo(ax + 30, by + 50);
    ctx.moveTo(ax + 18, by + 37); ctx.lineTo(ax + 31, by + 50); ctx.lineTo(ax + 18, by + 63);
    ctx.stroke();

    ctx.font = `700 32px ${font}`;
    const jt = 'Free Join Now';
    const jw = ctx.measureText(jt).width + 80;
    const jx = M + vw + 28;
    roundRect(ctx, jx, by + 12, jw, 76, 38);
    ctx.fillStyle = 'rgba(255,255,255,0.08)';
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.strokeStyle = 'rgba(255,255,255,0.85)';
    ctx.stroke();
    ctx.fillStyle = '#fff';
    ctx.fillText(jt, jx + 40, by + 62);

    // ---- footer: the real voting URL + QR ----
    ctx.fillStyle = 'rgba(255,255,255,0.14)';
    ctx.fillRect(M, 898, W, 2);
    const qs = 132;
    const qx = S - M - qs;
    const qy = 916;
    roundRect(ctx, qx, qy, qs, qs, 16);
    ctx.fillStyle = '#fff';
    ctx.fill();
    // Low error correction keeps long links at a small version; whole-pixel cells keep it scannable.
    const qr = qrcode(0, 'L');
    qr.addData(d.url);
    qr.make();
    const n = qr.getModuleCount();
    const cell = Math.max(2, Math.floor((qs - 12) / n));
    const qo = (qs - cell * n) / 2;
    ctx.fillStyle = '#0A1428';
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) ctx.fillRect(qx + qo + c * cell, qy + qo + r * cell, cell, cell);

    const fw = qx - 28 - M;
    const host = (u) => String(u || '').replace(/^https?:\/\//, '').replace(/\/$/, '');
    ctx.fillStyle = gold;
    ctx.font = `800 20px ${font}`;
    spaced(ctx, 3);
    ctx.fillText('VOTE AT · OR SCAN THE QR', M, 946);
    spaced(ctx, 0);
    ctx.fillStyle = '#fff';
    // Very long slugs shrink, then shorten in the middle (the QR always carries the full link).
    let link = host(d.url);
    fitFont(ctx, link, 800, 36, 20, fw, font);
    while (ctx.measureText(link).width > fw && link.length > 24) link = `${link.slice(0, 20)}…${link.slice(-(link.length - 22))}`;
    ctx.fillText(link, M, 992);
    const join = `Free to join: ${host(d.joinUrl || 'metasoftbd.com')}`;
    ctx.fillStyle = 'rgba(255,255,255,0.65)';
    fitFont(ctx, join, 600, 22, 16, fw, font);
    ctx.fillText(join, M, 1032);
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
