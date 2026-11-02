import { realtime, trackPresenceChannel, untrackPresenceChannel } from '../echo.js';
import { registerGlobalPresence } from '../presence.js';
import { resolveChannels } from '../channel-refresh.js';

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

        const initialName = box.getAttribute('data-channel');
        if (!initialName) {
            return;
        }

        setState(box, 'Loading presence…');

        const members = new Map();
        let settled = false;
        let channelName = null;
        let lastErrorChannel = null;

        const snapshot = () => [...members.values()];

        // Rotation: like chat channels, roster names change when access
        // changes; rejoining re-checks visibility and opt-in (P1-2).
        function subscribe(name) {
            if (channelName) {
                try {
                    echo.leave(channelName);
                } catch (_) {}
                untrackPresenceChannel(channelName);
                members.clear();
                settled = false;
            }

            channelName = name.replace(/^presence-/, '');
            box.setAttribute('data-channel', `presence-${channelName}`);

            let channel;
            try {
                channel = echo.join(channelName);
                trackPresenceChannel(channelName);
            } catch (_) {
                setState(box, 'Presence is unavailable right now.');
                return;
            }

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

            channel.listen('.channel.rotated', (event) => {
                if (event && typeof event.channel === 'string' && event.channel !== channelName) {
                    subscribe(event.channel);
                }
            });

            // Auth failure after an offline rotation: re-resolve the
            // current roster name before concluding anything.
            channel.error(() => {
                if (channelName === lastErrorChannel) {
                    return;
                }
                lastErrorChannel = channelName;
                resolveChannels(fetch, box.getAttribute('data-channels-url')).then((resolved) => {
                    if (resolved.status === 'ok' && resolved.channels.presence && resolved.channels.presence !== channelName) {
                        subscribe(resolved.channels.presence);
                    } else if (resolved.status === 'denied') {
                        setState(box, 'Presence is unavailable: you no longer have access here.');
                    } else {
                        setState(box, 'Presence is unavailable right now — this may mean you opted out, or the connection dropped.');
                    }
                });
            });
        }

        subscribe(initialName);

        setTimeout(() => {
            if (!settled) {
                setState(box, 'Presence is unavailable right now.');
            }
        }, 10000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-presence-roster]').forEach(initRoster);

        const echo = realtime();
        if (echo) {
            registerGlobalPresence(document, {
                join: (name) => {
                    const channel = echo.join(name);
                    return {
                        listen: (event, cb) => channel.listen(event, cb),
                        onError: (cb) => channel.error(cb),
                    };
                },
                leave: (name) => echo.leave(name),
                track: trackPresenceChannel,
                untrack: untrackPresenceChannel,
                resolve: async (url) => {
                    const resolved = await resolveChannels(fetch, url);
                    return resolved.status === 'ok' ? resolved.channels.presence : null;
                },
            });
        }
    });
})();
