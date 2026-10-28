import { currentUserId, realtime } from '../echo.js';

// Presence roster: who is here right now. Members appear only when they
// opted in (enforced by the channel auth callback, not just by this UI).
// Away state derives from recent activity whispers; nothing is persisted.
(function () {
    'use strict';

    const AWAY_AFTER_MS = 5 * 60 * 1000;
    const ACTIVITY_BEAT_MS = 30 * 1000;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function setState(box, text) {
        const list = box.querySelector('[data-presence-list]');
        if (list) {
            list.innerHTML = `<p class="text-sm text-zinc-500" data-presence-state>${escapeHtml(text)}</p>`;
        }
    }

    function render(box, members, activity) {
        const list = box.querySelector('[data-presence-list]');
        if (!list) {
            return;
        }

        const now = Date.now();
        const rows = members
            .map((member) => {
                const lastActive = activity.get(String(member.id)) ?? member.joinedAt ?? 0;
                const away = now - lastActive > AWAY_AFTER_MS;
                return { member, away };
            })
            .sort((a, b) => Number(a.away) - Number(b.away) || String(a.member.name).localeCompare(String(b.member.name)));

        if (rows.length === 0) {
            setState(box, 'Nobody else is here right now.');
            return;
        }

        list.innerHTML = `<ul class="space-y-2">${rows.map(({ member, away }) => `
            <li class="flex items-center gap-2 text-sm">
                <span class="relative h-8 w-8 shrink-0" aria-hidden="true">${
                    member.avatar_url
                        ? `<img src="${escapeHtml(member.avatar_url)}" alt="" class="h-8 w-8 rounded-full object-cover">`
                        : `<span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-700 text-[11px] font-bold text-white">${escapeHtml(member.initials ?? '?')}</span>`
                }
                    <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white ${away ? 'bg-zinc-400' : 'bg-emerald-500'}"></span>
                </span>
                <a href="${escapeHtml(member.profile_url ?? '#')}" class="min-w-0 flex-1 truncate font-semibold hover:text-emerald-700">${escapeHtml(member.name)}${member.is_maintainer ? ' <span class="text-xs font-normal text-zinc-500">· maintainer</span>' : ''}</a>
                <span class="text-xs ${away ? 'text-zinc-400' : 'text-emerald-700'}">${away ? 'Away' : 'Online'}</span>
            </li>`).join('')}</ul>`;
    }

    function initRoster(box) {
        const echo = realtime();
        if (!echo) {
            setState(box, 'Sign in to see who is here.');
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
        const activity = new Map();
        let settled = false;

        const snapshot = () => [...members.values()];

        channel.here((initial) => {
            settled = true;
            const now = Date.now();
            (initial ?? []).forEach((member) => {
                const info = member.info ?? member;
                const id = String(member.id ?? info.id);
                members.set(id, { ...info, id, joinedAt: now });
                if (!activity.has(id)) {
                    activity.set(id, now);
                }
            });
            render(box, snapshot(), activity);
        });

        channel.joining((member) => {
            const info = member.info ?? member;
            const id = String(member.id ?? info.id);
            members.set(id, { ...info, id, joinedAt: Date.now() });
            activity.set(id, Date.now());
            render(box, snapshot(), activity);
        });

        channel.leaving((member) => {
            const info = member.info ?? member;
            members.delete(String(member.id ?? info.id));
            render(box, snapshot(), activity);
        });

        channel.listenForWhisper('activity', (event) => {
            if (event.id) {
                activity.set(String(event.id), Date.now());
                if (settled) {
                    render(box, snapshot(), activity);
                }
            }
        });

        channel.error(() => {
            setState(box, 'Presence is unavailable right now — this may mean you opted out, or the connection dropped.');
        });

        setTimeout(() => {
            if (!settled) {
                setState(box, 'Presence is unavailable right now.');
            }
        }, 10000);

        // Heartbeat: tell the room we are still active (throttled).
        let lastBeat = 0;
        const beat = () => {
            const now = Date.now();
            if (now - lastBeat < ACTIVITY_BEAT_MS) {
                return;
            }
            lastBeat = now;
            try {
                channel.whisper('activity', { id: currentUserId() });
            } catch (_) {}
        };

        ['pointerdown', 'keydown'].forEach((eventName) => {
            document.addEventListener(eventName, beat, { passive: true });
        });
        setInterval(beat, ACTIVITY_BEAT_MS);
        setInterval(() => {
            if (settled) {
                render(box, snapshot(), activity);
            }
        }, 30000);

        beat();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-presence-roster]').forEach(initRoster);
    });
})();
