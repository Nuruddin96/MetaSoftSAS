import './bootstrap';

// ---- sidebar nav toggle (layouts/panel.blade.php, layouts/super.blade.php) ----
document.getElementById('navToggle')?.addEventListener('click', () => {
    document.getElementById('navMenu')?.classList.toggle('hidden');
    document.getElementById('navToggleOpenIcon')?.classList.toggle('hidden');
    document.getElementById('navToggleCloseIcon')?.classList.toggle('hidden');
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
