{{--
    Shared JS for the 3-pane inbox shell — included once per full page load
    (tenant.inbox, whatsapp/show.blade.php, messenger/show.blade.php).
    Two things this fixes over a naive innerHTML swap (see the redesign
    plan): (1) polling must be a named, re-callable function so switching
    conversations doesn't leave a stale setInterval running against the old
    conversation while never starting one for the new one — injected
    <script> tags don't execute via innerHTML, so a fire-and-forget inline
    script per thread partial would simply never run again after a swap.
    (2) the lightbox overlay (rendered by _thread.blade.php's @once block,
    present on every full page AND every ?panel=1 fetch response) must not
    end up duplicated in the DOM across swaps — deduped client-side here
    rather than trying to suppress it server-side, which would have meant
    touching messenger/_thread.blade.php (also used, unrelated, by
    orders/show.blade.php) to special-case a caller it doesn't know about.

    Fetch-swap is desktop-only (lg: and above) — on a narrow viewport,
    conversation rows just navigate normally, since the list and thread
    panes are never shown together on mobile (see the shell layout's
    responsive classes) and a real page load already renders the correct
    single-pane state for each page on its own.
--}}
<script>
(function () {
    const DESKTOP_BREAKPOINT = 1024;

    window.clearInboxPoll = function () {
        if (window.__inboxPollHandle) {
            clearInterval(window.__inboxPollHandle);
            window.__inboxPollHandle = null;
        }
    };

    function dedupeLightbox(fragment) {
        ['waLightboxOverlay', 'lightboxOverlay'].forEach((id) => {
            if (document.getElementById(id) && fragment.querySelector('#' + id)) {
                fragment.querySelector('#' + id).remove();
            }
        });
    }

    // Was the reader already at (or very near) the bottom of the thread
    // before this poll tick's messages were appended? Only that case should
    // auto-scroll — otherwise appending steals the scroll position out from
    // under someone reading older history. 48px covers sub-pixel rounding
    // and the last bubble's own padding without being loose enough to
    // trigger while genuinely scrolled up.
    function isNearBottom(el) {
        return el.scrollHeight - el.scrollTop - el.clientHeight <= 48;
    }

    // "↓ N new messages" pill, floated above the composer. One live
    // instance per open thread — created lazily inside the thread's
    // scroll-parent (not the scrolling element itself, so it stays fixed
    // in place while #threadMessages/#waThreadMessages scrolls under it)
    // and torn down whenever a new thread is initialized (desktop swap or
    // a fresh page load both call init*ThreadPolling again from scratch).
    function ensureNewMessagesPill(thread) {
        const container = thread.parentElement;
        if (!container) return null;
        container.querySelector('.new-msg-pill')?.remove();
        if (getComputedStyle(container).position === 'static') {
            container.classList.add('relative');
        }

        const pill = document.createElement('button');
        pill.type = 'button';
        pill.className = 'new-msg-pill hidden absolute bottom-3 left-1/2 -translate-x-1/2 z-10 items-center gap-1.5 bg-ink text-white text-xs font-semibold px-3.5 py-2 rounded-pill shadow-lg active:scale-95 transition';
        pill.dataset.count = '0';
        pill.innerHTML = '<span class="pill-count"></span><span>নতুন মেসেজ</span> ↓';

        function hide() {
            pill.classList.add('hidden');
            pill.classList.remove('flex');
            pill.dataset.count = '0';
        }

        pill.addEventListener('click', () => {
            thread.scrollTo({ top: thread.scrollHeight, behavior: 'smooth' });
            hide();
        });

        // Reader scrolled back to the bottom themselves — no need to keep
        // nudging them, so the pill dismisses on its own.
        thread.addEventListener('scroll', () => {
            if (isNearBottom(thread)) hide();
        });

        container.appendChild(pill);

        return {
            bump(newCount) {
                const total = parseInt(pill.dataset.count || '0', 10) + newCount;
                pill.dataset.count = String(total);
                pill.querySelector('.pill-count').textContent = total + ' ';
                pill.classList.remove('hidden');
                pill.classList.add('flex');
            },
        };
    }

    window.initWhatsAppThreadPolling = function (root) {
        const thread = root.querySelector('#waThreadMessages');
        if (!thread) return;

        window.clearInboxPoll();

        // Server-rendered thread scrolls to the newest message on its own
        // (see below) only via this init call — the #waThreadMessages
        // container is a fixed-height, overflow-y-auto pane (h-full inside
        // the conversation's flex layout), so without this it would default
        // to showing the OLDEST messages first on every open/swap.
        thread.scrollTop = thread.scrollHeight;
        const newMsgPill = ensureNewMessagesPill(thread);

        let afterId = parseInt(thread.dataset.lastId || '0', 10);
        const waId = root.dataset.externalId;
        const updatesUrl = root.dataset.updatesUrl;
        const deliveryLabel = { sent: '✓ পাঠানো হয়েছে', delivered: '✓✓ ডেলিভার হয়েছে', read: '✓✓ পড়া হয়েছে', failed: '⚠️ ব্যর্থ' };

        function buildAttachmentEl(m) {
            const wrap = document.createElement('div');
            wrap.className = 'mt-1.5';
            const mediaUrl = (m.direction === 'out' ? m.attachment_url : m.inbound_media_url) || null;

            if (mediaUrl && m.attachment_type === 'image') {
                const img = document.createElement('img');
                img.src = mediaUrl; img.alt = 'ছবি'; img.loading = 'lazy';
                img.className = 'max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer';
                img.onclick = () => openWaLightbox(mediaUrl);
                wrap.appendChild(img);
            } else if (mediaUrl && m.attachment_type === 'video') {
                const video = document.createElement('video');
                video.controls = true; video.preload = 'none'; video.className = 'max-w-[240px] rounded-lg';
                const source = document.createElement('source'); source.src = mediaUrl;
                video.appendChild(source); wrap.appendChild(video);
            } else if (mediaUrl && m.attachment_type === 'audio') {
                const audio = document.createElement('audio');
                audio.controls = true; audio.preload = 'none'; audio.className = 'max-w-[240px]';
                const source = document.createElement('source'); source.src = mediaUrl;
                audio.appendChild(source); wrap.appendChild(audio);
            } else if (mediaUrl) {
                const a = document.createElement('a');
                a.href = mediaUrl; a.target = '_blank'; a.className = 'block underline text-xs';
                a.textContent = '📎 ' + (m.attachment_name || 'ফাইল দেখুন');
                wrap.appendChild(a);
            } else if (m.attachment_type) {
                const p = document.createElement('p');
                p.className = 'text-xs opacity-80';
                p.textContent = 'মিডিয়া পাঠিয়েছে — মিডিয়া লোড করা যায়নি';
                wrap.appendChild(p);
            } else {
                return null;
            }
            return wrap;
        }

        function appendBubble(m) {
            if (thread.querySelector(`.msg-bubble[data-id="${m.id}"]`)) return;

            const wrap = document.createElement('div');
            wrap.className = 'msg-bubble flex ' + (m.direction === 'out' ? 'justify-end' : 'justify-start');
            wrap.dataset.id = m.id;

            const bubble = document.createElement('div');
            bubble.className = 'max-w-[85%] sm:max-w-md break-words ' + (m.direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink') + ' rounded-card px-4 py-2.5 text-sm';

            if (m.message_text) {
                const text = document.createElement('div');
                text.textContent = m.message_text;
                bubble.appendChild(text);
            }

            const attachmentEl = buildAttachmentEl(m);
            if (attachmentEl) bubble.appendChild(attachmentEl);

            const meta = document.createElement('div');
            meta.className = 'flex items-center gap-1.5 mt-1';
            const time = document.createElement('p');
            time.className = 'text-[10px] opacity-70';
            time.textContent = m.time_label || '';
            meta.appendChild(time);
            if (m.direction === 'out' && m.delivery_status) {
                const status = document.createElement('span');
                status.className = 'text-[10px] opacity-70';
                status.textContent = deliveryLabel[m.delivery_status] || m.delivery_status;
                meta.appendChild(status);
            }
            bubble.appendChild(meta);

            wrap.appendChild(bubble);
            thread.appendChild(wrap);
        }

        async function poll() {
            try {
                const wasNearBottom = isNearBottom(thread);
                const res = await fetch(`${updatesUrl}?after_id=${afterId}&wa_id=${encodeURIComponent(waId)}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                afterId = data.latest_id;
                (data.messages || []).forEach(appendBubble);
                const added = (data.messages || []).length;
                if (added && wasNearBottom) {
                    thread.scrollTop = thread.scrollHeight;
                } else if (added) {
                    newMsgPill?.bump(added);
                }
            } catch (e) { /* silent — next tick tries again */ }
        }

        window.__inboxPollHandle = setInterval(poll, 4000);
    };

    window.initMessengerThreadPolling = function (root) {
        const thread = root.querySelector('#threadMessages');
        if (!thread) return;

        window.clearInboxPoll();

        thread.scrollTop = thread.scrollHeight;
        const newMsgPill = ensureNewMessagesPill(thread);

        let afterId = parseInt(thread.dataset.lastId || '0', 10);
        const psid = root.dataset.externalId;
        const updatesUrl = root.dataset.updatesUrl;

        function buildAttachmentEl(url, type, name) {
            const wrap = document.createElement('div');
            wrap.className = 'mt-1.5';
            const fallback = document.createElement('p');
            fallback.className = 'hidden text-xs opacity-80';

            if (type === 'image') {
                const img = document.createElement('img');
                img.src = url; img.alt = 'ছবি'; img.loading = 'lazy';
                img.className = 'max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer';
                img.onclick = () => openLightbox(url);
                fallback.textContent = '⚠️ ছবিটি আর পাওয়া যাচ্ছে না';
                img.onerror = () => { img.style.display = 'none'; fallback.classList.remove('hidden'); };
                wrap.append(img, fallback);
            } else if (type === 'audio') {
                const holder = document.createElement('div');
                holder.className = 'msgr-audio';
                holder.dataset.url = url;
                wrap.append(holder);
                mountMessengerAudio(holder, url);
            } else if (type === 'video') {
                const video = document.createElement('video');
                video.controls = true; video.preload = 'none'; video.className = 'max-w-[240px] rounded-lg';
                const source = document.createElement('source'); source.src = url;
                video.appendChild(source);
                fallback.textContent = '⚠️ ভিডিওটি আর পাওয়া যাচ্ছে না';
                video.onerror = () => { video.style.display = 'none'; fallback.classList.remove('hidden'); };
                wrap.append(video, fallback);
            } else {
                const a = document.createElement('a');
                a.href = url; a.target = '_blank'; a.className = 'block underline text-xs';
                a.textContent = '📎 ' + (name || 'এটাচমেন্ট দেখুন');
                wrap.appendChild(a);
            }
            return wrap;
        }

        function appendBubble(m) {
            if (thread.querySelector(`.msg-bubble[data-id="${m.id}"]`)) return;

            const wrap = document.createElement('div');
            wrap.className = 'msg-bubble flex ' + (m.direction === 'out' ? 'justify-end' : 'justify-start');
            wrap.dataset.id = m.id;

            const bubble = document.createElement('div');
            bubble.className = 'max-w-[85%] sm:max-w-md break-words ' + (m.direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink') + ' rounded-card px-4 py-2.5 text-sm';

            if (m.message_text) {
                const text = document.createElement('div');
                text.textContent = m.message_text;
                bubble.appendChild(text);
            }
            if (m.attachment_url) {
                bubble.appendChild(buildAttachmentEl(m.attachment_url, m.attachment_type, m.attachment_name));
            }

            const time = document.createElement('p');
            time.className = 'text-[10px] mt-1 opacity-70';
            time.textContent = m.time_label || '';
            bubble.appendChild(time);

            wrap.appendChild(bubble);
            thread.appendChild(wrap);
        }

        async function poll() {
            try {
                const wasNearBottom = isNearBottom(thread);
                const res = await fetch(`${updatesUrl}?after_id=${afterId}&psid=${encodeURIComponent(psid)}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                afterId = data.latest_id;
                (data.messages || []).forEach(appendBubble);
                const added = (data.messages || []).length;
                if (added && wasNearBottom) {
                    thread.scrollTop = thread.scrollHeight;
                } else if (added) {
                    newMsgPill?.bump(added);
                }
            } catch (e) { /* silent */ }
        }

        window.__inboxPollHandle = setInterval(poll, 4000);
    };

    function initPollingFor(root) {
        if (!root) return;
        if (root.dataset.channel === 'whatsapp') window.initWhatsAppThreadPolling(root);
        else if (root.dataset.channel === 'messenger') window.initMessengerThreadPolling(root);
    }

    // Initial load already has a conversation open (a direct link to a
    // WhatsApp/Messenger thread, not the bare inbox shell) — start polling
    // for it immediately, same as before this redesign.
    document.addEventListener('DOMContentLoaded', () => initPollingFor(document.getElementById('conversationArea')));

    // Off-canvas customer-info drawer (see _conversation.blade.php) —
    // delegated, same as the row-click handler below, since the drawer
    // itself is part of #conversationArea and gets replaced on every swap.
    document.addEventListener('click', function (e) {
        if (e.target.closest('.conv-info-toggle')) {
            document.querySelector('.conv-info-drawer')?.classList.remove('hidden');
        } else if (e.target.closest('.conv-info-close') || e.target.closest('.conv-info-backdrop')) {
            document.querySelector('.conv-info-drawer')?.classList.add('hidden');
        }
    });

    // Desktop-only conversation-row fetch-swap. Event delegation on the
    // list container (not the individual rows) since rows get replaced
    // wholesale on every navigation and appended by "load more".
    document.addEventListener('click', async function (e) {
        const row = e.target.closest('.conv-row');
        if (!row || window.innerWidth < DESKTOP_BREAKPOINT) return;

        const pane = document.getElementById('conversationPane');
        if (!pane) return; // not on a shell page (shouldn't happen, but don't hijack navigation if so)

        e.preventDefault();
        const url = row.dataset.showUrl || row.href;

        // Loading state — the fetch is usually near-instant, but a visible
        // pane-dim avoids the panel looking unresponsive on a slow connection.
        pane.classList.add('opacity-50', 'pointer-events-none');

        try {
            const res = await fetch(url + (url.includes('?') ? '&' : '?') + 'panel=1', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error('non-2xx');
            const html = await res.text();

            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const newRoot = parsed.getElementById('conversationArea');
            if (!newRoot) throw new Error('missing conversationArea in response');

            dedupeLightbox(parsed.body);

            pane.innerHTML = '';
            pane.appendChild(newRoot);
            pane.classList.remove('opacity-50', 'pointer-events-none');
            // Any lightbox markup the fetched partial brought along (not
            // deduped, meaning the page didn't already have one) needs to
            // land in the document too, not just inside #conversationPane —
            // its own JS looks it up by a fixed id anywhere in the DOM, and
            // leaving it nested is harmless either way, but appending
            // anything else the partial carried (should be none today,
            // reserved for future) keeps this forward-compatible.
            Array.from(parsed.body.children).forEach((el) => {
                if (el.id && el.id !== 'conversationArea' && !document.getElementById(el.id)) {
                    document.body.appendChild(el);
                }
            });

            if (window.lucide) window.lucide.createIcons();

            history.pushState(null, '', url);

            document.querySelectorAll('.conv-row').forEach((r) => r.classList.remove('bg-leaf/10'));
            row.classList.add('bg-leaf/10');

            initPollingFor(newRoot);
        } catch (err) {
            window.location.href = url;
        }
    });

    window.addEventListener('popstate', () => window.location.reload());
})();
</script>
