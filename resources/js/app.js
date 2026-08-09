import './bootstrap';

// ---- sidebar nav toggle (layouts/panel.blade.php, layouts/super.blade.php) ----
document.getElementById('navToggle')?.addEventListener('click', () => {
    document.getElementById('navMenu')?.classList.toggle('hidden');
    document.getElementById('navToggleOpenIcon')?.classList.toggle('hidden');
    document.getElementById('navToggleCloseIcon')?.classList.toggle('hidden');
});

// ---- notification bell (layouts/panel.blade.php) ----
const notifBtn = document.getElementById('notifBtn');
const notifPanel = document.getElementById('notifPanel');
notifBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    notifPanel.classList.toggle('hidden');
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
