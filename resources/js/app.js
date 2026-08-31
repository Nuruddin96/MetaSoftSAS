import './bootstrap';
import Sortable from 'sortablejs';

// ---- sidebar nav toggle (layouts/panel.blade.php, layouts/super.blade.php) ----
document.getElementById('navToggle')?.addEventListener('click', () => {
    document.getElementById('navMenu')?.classList.toggle('hidden');
    document.getElementById('navToggleOpenIcon')?.classList.toggle('hidden');
    document.getElementById('navToggleCloseIcon')?.classList.toggle('hidden');
    // #mobileBottomNav only exists on layouts/panel.blade.php (super.blade.php
    // has no bottom tab bar) — hidden while the hamburger drawer is open so
    // its own "Home" tab doesn't show through underneath as a duplicated
    // Dashboard entry.
    document.getElementById('mobileBottomNav')?.classList.toggle('hidden');
});

// ---- notification bell (layouts/panel.blade.php) ----
// Opening the bell marks every category "seen" — see
// NotificationController::markSeen(). sendBeacon (not fetch) because this
// fires from a click that may be immediately followed by navigation
// elsewhere on the page; a beacon is guaranteed to still go out. The badge
// hides immediately client-side (instant feedback) while the session mark
// persisted server-side is what keeps it hidden across a page refresh.
const notifBtn = document.getElementById('notifBtn');
const notifPanel = document.getElementById('notifPanel');
const notifBadge = document.getElementById('notifBadge');
notifBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    const opening = notifPanel.classList.contains('hidden');
    notifPanel.classList.toggle('hidden');

    if (opening) {
        notifBadge?.classList.add('hidden');
        const seenUrl = notifBtn.dataset.seenUrl;
        if (seenUrl && navigator.sendBeacon) {
            navigator.sendBeacon(seenUrl, new URLSearchParams({ _token: notifBtn.dataset.csrf }));
        }
    }
});
document.addEventListener('click', (e) => {
    if (!notifPanel?.contains(e.target) && e.target !== notifBtn) notifPanel?.classList.add('hidden');
});

// ---- toast notifications (layouts/panel.blade.php) ----
window.showToast = function showToast(message, type = 'success') {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    const el = document.createElement('div');
    const styles = {
        success: 'bg-white border border-leaf/30 text-leafdk',
        error: 'bg-white border border-red-200 text-red-700',
    };
    el.className = 'toast ' + (styles[type] || styles.success);
    el.innerHTML = (type === 'error' ? '⚠️ ' : '✅ ') + message;
    stack.appendChild(el);
    setTimeout(() => {
        el.classList.add('leaving');
        setTimeout(() => el.remove(), 200);
    }, 4000);
};

// Flash messages queued by layouts/panel.blade.php (window.__flashMessages)
// before this module had loaded — drained here, right after showToast is
// defined, so they always fire regardless of module-load timing.
(window.__flashMessages || []).forEach(({ message, type }) => window.showToast(message, type));

// ---- button loading state on form submit (layouts/panel.blade.php) ----
document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', function () {
        if (form.dataset.noLoading) return;
        const btn = form.querySelector('button[type="submit"], button:not([type])');
        if (btn && !btn.classList.contains('btn-loading')) {
            btn.dataset.originalText = btn.innerHTML;
            btn.classList.add('btn-loading');
            btn.innerHTML = '<span class="btn-spinner"></span>' + btn.innerHTML;
        }
    });
});

// ---- top progress bar for full-page navigations (layouts/panel.blade.php,
// #navProgress) ----
// This app is classic server-rendered Blade (every link is a real page
// load, not a SPA route change), so this only needs to *start*: the
// in-flight bar is naturally torn down the instant the browser replaces
// the document with the next page. Scoped to plain same-origin <a> clicks
// only — form submits already get the .btn-loading spinner above, and
// several forms in this app are fetch/AJAX-driven rather than real
// navigations (e.g. the fraud-check button), where a nav bar that never
// resolves would just look stuck.
const navProgress = document.getElementById('navProgress');
document.addEventListener('click', (e) => {
    if (!navProgress) return;
    const link = e.target.closest('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    let url;
    try {
        url = new URL(link.href, window.location.href);
    } catch {
        return;
    }
    if (url.origin !== window.location.origin) return;
    if (url.pathname === window.location.pathname && url.hash) return; // in-page anchor only

    navProgress.classList.remove('is-done');
    void navProgress.offsetWidth; // restart the width transition if a previous nav had one queued
    navProgress.classList.add('is-active');
});
// Covers a back/forward-cache restore leaving the bar visibly stuck mid-flight.
window.addEventListener('pageshow', () => navProgress?.classList.remove('is-active'));

// ---- PWA: service worker registration + "new version" prompt ----
// window.__swUrl is queued by layouts/panel.blade.php the same way
// __flashMessages is, since this module loads deferred.
if ('serviceWorker' in navigator && window.__swUrl) {
    const showUpdatePrompt = (registration) => {
        const stack = document.getElementById('toastStack');
        if (!stack || document.getElementById('swUpdateToast')) return; // don't stack duplicates

        const el = document.createElement('div');
        el.id = 'swUpdateToast';
        el.className = 'toast bg-white border border-leaf/30 text-ink flex items-center gap-3';
        el.innerHTML = '<span class="flex-1">নতুন ভার্সন পাওয়া গেছে</span>'
            + '<button type="button" class="font-semibold text-leafdk shrink-0">রিফ্রেশ করুন</button>';
        el.querySelector('button').addEventListener('click', () => {
            registration.waiting?.postMessage('SKIP_WAITING');
        });
        stack.appendChild(el);
        // Deliberately no auto-dismiss timeout (unlike showToast()) — this
        // is actionable, not informational, and should stay until the user
        // either updates or the tab is reloaded some other way.
    };

    navigator.serviceWorker.register(window.__swUrl).then((registration) => {
        if (registration.waiting) showUpdatePrompt(registration);

        registration.addEventListener('updatefound', () => {
            const installing = registration.installing;
            installing?.addEventListener('statechange', () => {
                if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                    showUpdatePrompt(registration);
                }
            });
        });
    }).catch(() => {
        // Registration failing (unsupported browser, blocked, private
        // mode, etc.) must never break the app — every feature works
        // identically without it, this only loses the installable/
        // offline-shell layer.
    });

    let reloadedForUpdate = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloadedForUpdate) return;
        reloadedForUpdate = true;
        window.location.reload();
    });
}

// ---- Push notifications: permission value-prop + subscribe
// (layouts/panel.blade.php, #pushPromptTip, window.__push) ----
// Never call Notification.requestPermission() on page load — only from a
// real click on "নোটিফিকেশন চালু করুন" below, same rule the mobile audit's
// Part 6 calls out (a cold native prompt on first open reliably gets
// denied and is hard to walk back). window.metasoftEnablePush is exposed
// so the Settings → Notifications page can reuse this exact flow for
// someone who dismissed the banner and wants to opt in later, instead of
// a second, drifting copy of the same subscribe logic.
(function () {
    const push = window.__push;
    const tip = document.getElementById('pushPromptTip');
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = atob(base64);
        return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
    }

    async function subscribe() {
        if (!push?.vapidKey) return false;
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(push.vapidKey),
            });

            const response = await fetch(push.subscribeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': push.csrf },
                body: JSON.stringify({
                    subscription: subscription.toJSON(),
                    device_name: navigator.platform || null,
                }),
            });

            // The browser-side subscription can succeed while the server
            // fails to record it (validation error, table not imported
            // yet, tenant/cross-device edge case) — fetch() only rejects
            // on a network failure, never on a non-2xx status, so this
            // must be checked explicitly. Reporting "enabled" here without
            // it would mean a push silently never arrives with no way to
            // tell why (see the pre-production review, B.4).
            return response.ok;
        } catch (e) {
            // Denied mid-flow, unsupported browser quirk, network error —
            // Settings → Notifications still offers a manual retry either way.
            return false;
        }
    }

    // Never surfaces response bodies, status codes, or anything else that
    // could leak server internals — a single, fixed, non-technical message,
    // shown at most once per click (not a recurring/nagging popup: reuses
    // the existing showToast(), which already auto-dismisses).
    window.metasoftEnablePush = async function metasoftEnablePush() {
        if (!supported) return false;
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return false;

        const ok = await subscribe();
        if (!ok) {
            window.showToast?.('নোটিফিকেশন চালু করা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'error');
        }
        return ok;
    };

    if (!tip || !supported || !push?.vapidKey) return;

    const dismissKey = 'ms_push_prompt_dismissed';
    try {
        if (!localStorage.getItem(dismissKey) && Notification.permission === 'default') {
            tip.classList.remove('hidden');
        }
    } catch (e) {
        // Storage blocked (private mode, locked-down browser) — just skip
        // the banner rather than risk showing it on every single page load.
    }

    document.getElementById('pushPromptAllow')?.addEventListener('click', async () => {
        tip.classList.add('hidden');
        try { localStorage.setItem(dismissKey, '1'); } catch (e) { /* ignore */ }
        await window.metasoftEnablePush();
    });

    document.getElementById('pushPromptLater')?.addEventListener('click', () => {
        tip.classList.add('hidden');
        try { localStorage.setItem(dismissKey, '1'); } catch (e) { /* ignore */ }
    });
})();

// ---- "Install App" button (central/landing.blade.php, #pwaInstallBanner) ----
// Guarded on the button's existence like every other feature block in this
// file — this only runs on the landing page, a no-op everywhere else.
(function () {
    const installBtn = document.getElementById('pwaInstallBtn');
    if (!installBtn) return;

    const banner = document.getElementById('pwaInstallBanner');
    const iosModal = document.getElementById('pwaIosModal');
    const iosModalClose = document.getElementById('pwaIosModalClose');

    const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true; // iOS Safari's own legacy flag, no matchMedia equivalent there
    const isIOS = () => /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

    const openIosModal = () => { iosModal?.classList.remove('hidden'); iosModal?.classList.add('flex'); };
    const closeIosModal = () => { iosModal?.classList.add('hidden'); iosModal?.classList.remove('flex'); };

    // Already running as an installed app — nothing to prompt, and no
    // banner to show in the first place. Banner's default markup class is
    // `hidden`; it is only ever revealed here, never flashed on then off.
    if (isStandalone()) {
        banner?.classList.add('hidden');
    } else {
        banner?.classList.remove('hidden');
    }

    // Chrome/Edge/Android and other Chromium browsers fire this ahead of
    // time when the page qualifies as installable; captured and replayed
    // on click instead of letting the browser show its own mini-infobar,
    // since the brief calls for the click itself to trigger the prompt.
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        banner?.classList.add('hidden');
        window.showToast('MetaSoft BD অ্যাপ ইনস্টল হয়েছে ✓', 'success');
    });

    installBtn.addEventListener('click', async () => {
        // 1. Android Chrome / other browsers that support the real prompt.
        if (deferredPrompt) {
            deferredPrompt.prompt();
            await deferredPrompt.userChoice; // resolves once the user accepts/dismisses the native dialog
            deferredPrompt = null;
            return;
        }

        // 2. iPhone/iPad Safari — no beforeinstallprompt exists there at
        // all, so a click with no captured prompt on an iOS device means
        // "show them how to do it manually" rather than "unsupported".
        if (isIOS()) {
            openIosModal();
            return;
        }

        // 3. Anything else that reaches here genuinely doesn't support
        // installable-web-app prompting (e.g. desktop Firefox today) —
        // say so rather than doing nothing on click.
        window.showToast('এই ব্রাউজারে অ্যাপ ইনস্টল সাপোর্ট নেই। Chrome ব্যবহার করে দেখুন।', 'error');
    });

    iosModalClose?.addEventListener('click', closeIosModal);
    iosModal?.addEventListener('click', (e) => {
        if (e.target === iosModal) closeIosModal(); // backdrop click only, not the card itself
    });
})();

// ---- Landing page builder: drag-and-drop section reorder
// (tenant/landing-pages/edit.blade.php, #sectionsSortable) ----
// The up/down arrow buttons (Tenant\LandingPageController::moveSection)
// keep working unchanged as a no-JS fallback — this only upgrades them to
// also support dragging, posting the full new order in one request
// (Tenant\LandingPageController::reorderSections) instead of one swap per
// move. Falls back to a no-op on failure/network error rather than
// reloading, so a dropped card never visually snaps back mid-interaction
// without at least a toast explaining why.
(function () {
    const list = document.getElementById('sectionsSortable');
    if (!list) return;

    Sortable.create(list, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: async () => {
            const order = [...list.children].map((el) => el.dataset.sectionId);
            try {
                const res = await fetch(list.dataset.reorderUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': list.dataset.csrf },
                    body: JSON.stringify({ order }),
                });
                if (!res.ok) throw new Error('reorder failed');
                window.showToast?.('সেকশনের ক্রম আপডেট হয়েছে।', 'success');
            } catch (e) {
                window.showToast?.('ক্রম সেভ করা যায়নি — পেজ রিফ্রেশ করে আবার চেষ্টা করুন।', 'error');
            }
        },
    });
})();

// ---- Landing page builder: responsive preview width toggle
// (tenant/landing-pages/edit.blade.php or design.blade.php, #previewFrame) ----
document.querySelectorAll('[data-preview-width]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const frame = document.getElementById('previewFrame');
        if (!frame) return;
        frame.style.width = btn.dataset.previewWidth;
        document.querySelectorAll('[data-preview-width]').forEach((b) => b.classList.remove('is-active'));
        btn.classList.add('is-active');
    });
});
