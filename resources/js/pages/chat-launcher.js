// Compact chat launcher for signed-in members: room switcher with unread
// dots. Unread state comes from a lightweight polled endpoint; message
// delivery itself stays on the socket.
(function () {
    'use strict';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function refresh(root) {
        const url = root.getAttribute('data-unread-url');
        if (!url) {
            return;
        }

        let data;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            data = await response.json();
        } catch (_) {
            return;
        }

        const rooms = data.rooms ?? [];
        const anyUnread = rooms.some((room) => room.unread);
        const dot = root.querySelector('[data-launcher-dot]');
        if (dot) {
            dot.classList.toggle('hidden', !anyUnread);
        }

        const list = root.querySelector('[data-launcher-rooms]');
        if (list) {
            list.innerHTML = rooms.map((room) => `
                <li>
                    <a href="${escapeHtml(room.url)}" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-zinc-100">
                        <span class="h-2 w-2 shrink-0 rounded-full ${room.unread ? 'bg-emerald-600' : 'bg-zinc-200'}"></span>
                        <span class="min-w-0 flex-1 truncate font-medium">${escapeHtml(room.label)}</span>
                    </a>
                </li>`).join('');
        }
    }

    function initLauncher(root) {
        const toggle = root.querySelector('[data-launcher-toggle]');
        const panel = root.querySelector('[data-launcher-panel]');

        toggle?.addEventListener('click', () => {
            panel?.classList.toggle('hidden');
            refresh(root);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                panel?.classList.add('hidden');
            }
        });

        refresh(root);
        setInterval(() => refresh(root), 60000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-chat-launcher]').forEach(initLauncher);
    });
})();
