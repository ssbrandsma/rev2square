import { realtime } from '../echo.js';

// Presence roster: who is here right now. Members appear only when they
// opted in (enforced by the channel auth callback, not just by this UI).
// The panel lists usernames only and collapses when nobody is around;
// nothing about presence is persisted.
(function () {
    'use strict';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function setCollapsed(box, collapsed) {
        const panel = box.closest('aside') ?? box;
        panel.classList.toggle('hidden', collapsed);
    }

    function setState(box, text) {
        setCollapsed(box, false);
        const list = box.querySelector('[data-presence-list]');
        if (list) {
            list.innerHTML = `<p class="text-sm text-zinc-500" data-presence-state>${escapeHtml(text)}</p>`;
        }
    }

    function render(box, members) {
        const list = box.querySelector('[data-presence-list]');
        if (!list) {
            return;
        }

        if (members.length === 0) {
            setCollapsed(box, true);
            return;
        }

        setCollapsed(box, false);
        const names = [...members]
            .sort((a, b) => String(a.name).localeCompare(String(b.name)));

        list.innerHTML = `<ul class="space-y-2">${names.map((member) => `
            <li class="text-sm">
                <a href="${escapeHtml(member.profile_url ?? '#')}" class="font-semibold hover:text-emerald-700">${escapeHtml(member.name)}</a>
            </li>`).join('')}</ul>`;
    }

    function initRoster(box) {
        const echo = realtime();
        if (!echo) {
            setState(box, 'Presence is unavailable right now.');
            return;
        }

        const channelName = box.getAttribute('data-channel');
        if (!channelName) {
            return;
        }

        setState(box, 'Loading presence…');

        let channel;
        try {
            channel = echo.join(channelName.replace(/^presence-/, ''));
        } catch (_) {
            setState(box, 'Presence is unavailable right now.');
            return;
        }

        const members = new Map();
        let settled = false;

        const snapshot = () => [...members.values()];

        channel.here((initial) => {
            settled = true;
            (initial ?? []).forEach((member) => {
                const info = member.info ?? member;
                const id = String(member.id ?? info.id);
                members.set(id, { ...info, id });
            });
            render(box, snapshot());
        });

        channel.joining((member) => {
            const info = member.info ?? member;
            const id = String(member.id ?? info.id);
            members.set(id, { ...info, id });
            render(box, snapshot());
        });

        channel.leaving((member) => {
            const info = member.info ?? member;
            members.delete(String(member.id ?? info.id));
            render(box, snapshot());
        });

        channel.error(() => {
            setState(box, 'Presence is unavailable right now — this may mean you opted out, or the connection dropped.');
        });

        setTimeout(() => {
            if (!settled) {
                setState(box, 'Presence is unavailable right now.');
            }
        }, 10000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-presence-roster]').forEach(initRoster);
    });
})();
