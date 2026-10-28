import { currentUserId, realtime } from '../echo.js';

// Live chat room: server-rendered history upgraded with realtime delivery.
// Sending always goes through HTTP (persisted, idempotent); the socket only
// delivers other people's messages, typing signals, and reconnect catch-up.
(function () {
    'use strict';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function setStatus(root, kind, html) {
        const banner = root.querySelector('[data-chat-status]');
        if (!banner) {
            return;
        }

        if (!kind) {
            banner.classList.add('hidden');
            banner.innerHTML = '';
            return;
        }

        banner.classList.remove('hidden');
        banner.className = 'mb-3 rounded-xl border px-4 py-3 text-sm ' + (kind === 'error'
            ? 'border-red-200 bg-red-50 text-red-900'
            : 'border-amber-200 bg-amber-50 text-amber-900');
        banner.innerHTML = html;
    }

    function lastMessageId(root) {
        const nodes = root.querySelectorAll('[data-chat-message]');
        if (nodes.length === 0) {
            return 0;
        }

        return Number(nodes[nodes.length - 1].getAttribute('data-message-id')) || 0;
    }

    function scrollToBottom(scroll) {
        scroll.scrollTop = scroll.scrollHeight;
    }

    function nearBottom(scroll) {
        return scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 120;
    }

    function appendMessage(root, payload) {
        if (root.querySelector(`[data-chat-message][data-message-id="${payload.id}"]`)) {
            return;
        }

        const empty = root.querySelector('[data-chat-empty]');
        if (empty) {
            empty.remove();
        }

        const scroll = root.querySelector('[data-chat-scroll]');
        const stick = nearBottom(scroll);
        const mine = Number(payload.author.id) === currentUserId();

        const wrapper = document.createElement('div');
        wrapper.setAttribute('data-chat-message', '');
        wrapper.setAttribute('data-message-id', String(payload.id));
        wrapper.id = `message-${payload.id}`;
        wrapper.className = 'flex items-start gap-3';
        const avatar = payload.author.avatar_url
            ? `<img src="${escapeHtml(payload.author.avatar_url)}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">`
            : `<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${mine ? 'bg-zinc-700' : 'bg-emerald-700'} text-xs font-bold text-white" aria-hidden="true">${escapeHtml(payload.author.initials)}</span>`;

        wrapper.innerHTML =
            `<a href="${escapeHtml(payload.author.profile_url)}" class="shrink-0" aria-label="${escapeHtml(payload.author.name)}’s profile">${avatar}</a>` +
            `<div class="min-w-0 flex-1">` +
            `<p class="flex flex-wrap items-center gap-x-2 text-sm">` +
            `<a href="${escapeHtml(payload.author.profile_url)}" class="font-bold hover:text-emerald-700">${escapeHtml(payload.author.name)}</a>` +
            (payload.author.is_maintainer ? `<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-900">Maintainer</span>` : '') +
            `<time datetime="${escapeHtml(payload.created_at)}" class="text-xs text-zinc-500">${escapeHtml(payload.created_human)}</time>` +
            `</p>` +
            `<div class="mt-0.5 text-[15px] leading-relaxed text-zinc-800 discussion-body">${payload.body_html}</div>` +
            `</div>`;

        scroll.appendChild(wrapper);

        if (stick) {
            scrollToBottom(scroll);
        }
    }

    async function postJson(url, payload, extraHeaders = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                ...extraHeaders,
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || `Request failed (${response.status})`);
        }

        return data;
    }

    function queueOutbox(root, body, clientKey) {
        const outbox = root.querySelector('[data-chat-outbox]');
        if (!outbox) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900';
        row.innerHTML = `<span class="min-w-0 flex-1 truncate">Could not send: ${escapeHtml(body.slice(0, 80))}</span>`;
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'shrink-0 font-semibold underline';
        retry.textContent = 'Retry';
        retry.addEventListener('click', async () => {
            retry.disabled = true;
            try {
                const data = await postJson(root.getAttribute('data-send-url'), { body, client_key: clientKey });
                appendMessage(root, data.message);
                row.remove();
            } catch (error) {
                retry.disabled = false;
                retry.textContent = 'Retry (failed again)';
            }
        });
        row.appendChild(retry);
        outbox.appendChild(row);
    }

    function markRead(root) {
        const url = root.getAttribute('data-read-url');
        if (!url || !currentUserId()) {
            return;
        }

        postJson(url, { last_read_message_id: lastMessageId(root) }).catch(() => {});
    }

    async function catchUp(root) {
        const historyUrl = root.getAttribute('data-history-url');
        const after = lastMessageId(root);

        try {
            const response = await fetch(`${historyUrl}?after_id=${after}&limit=100`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            (data.messages ?? []).forEach((message) => appendMessage(root, message));
            markRead(root);
        } catch (_) {
            // Stay on the rendered history; the banner explains.
        }
    }

    function watchTyping(root, channel) {
        const label = root.querySelector('[data-chat-typing]');
        if (!label) {
            return () => {};
        }

        const typists = new Map();

        const render = () => {
            const now = Date.now();
            const names = [];
            typists.forEach((at, name) => {
                if (now - at < 4000) {
                    names.push(name);
                }
            });

            if (names.length === 0) {
                label.classList.add('hidden');
                label.textContent = '';
                return;
            }

            label.classList.remove('hidden');
            label.textContent = names.length === 1
                ? `${names[0]} is typing…`
                : `${names.slice(0, 3).join(', ')} are typing…`;
        };

        setInterval(render, 1500);

        channel.listenForWhisper('typing', (event) => {
            if (event.name && event.id !== currentUserId()) {
                typists.set(event.name, Date.now());
                render();
            }
        });

        let lastSent = 0;
        return () => {
            const now = Date.now();
            if (now - lastSent < 3000) {
                return;
            }
            lastSent = now;
            try {
                channel.whisper('typing', { id: currentUserId(), name: root.getAttribute('data-user-name') });
            } catch (_) {}
        };
    }

    function initRoom(root) {
        const scroll = root.querySelector('[data-chat-scroll]');
        scrollToBottom(scroll);

        const form = root.querySelector('[data-chat-form]');
        const field = form?.querySelector('textarea[name="body"]');
        const draftKey = form?.getAttribute('data-draft-key');

        // Draft for the chat composer (shared helper covers discussion forms).
        if (form && field && draftKey) {
            try {
                const draft = window.localStorage.getItem('composer:' + draftKey);
                if (draft && field.value === '') {
                    field.value = draft;
                }
            } catch (_) {}

            field.addEventListener('input', () => {
                try {
                    if (field.value === '') {
                        window.localStorage.removeItem('composer:' + draftKey);
                    } else {
                        window.localStorage.setItem('composer:' + draftKey, field.value);
                    }
                } catch (_) {}
            });

            field.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    form.requestSubmit();
                }
            });
        }

        const echo = realtime();
        if (!echo) {
            return;
        }

        const socketHeaders = () => {
            try {
                const id = echo.socketId();
                return id ? { 'X-Socket-ID': id } : {};
            } catch (_) {
                return {};
            }
        };

        const channelName = root.getAttribute('data-channel');
        const channel = echo.private(channelName.replace(/^private-/, ''));

        channel.listen('.message.sent', (payload) => {
            appendMessage(root, payload);
            markRead(root);
        });

        const announceTyping = watchTyping(root, channel);
        field?.addEventListener('input', announceTyping);

        const pusher = echo.connector.pusher;
        if (pusher?.connection) {
            pusher.connection.bind('connecting', () => setStatus(root, 'warn', 'Connecting to live chat…'));
            pusher.connection.bind('connected', () => {
                setStatus(root, null);
                catchUp(root);
            });
            pusher.connection.bind('unavailable', () => setStatus(
                root,
                'error',
                'Live chat is unavailable — you are reading the saved history. ' +
                '<button type="button" data-chat-retry class="font-semibold underline">Reconnect</button> ' +
                'or <a href="" class="font-semibold underline">refresh</a>. Sending still works and others will see it when their connection returns.',
            ));
            pusher.connection.bind('disconnected', () => setStatus(root, 'warn', 'Reconnecting… new messages will catch up automatically.'));
            pusher.connection.bind('failed', () => setStatus(root, 'error', 'Live chat failed to connect. You are reading the saved history; sending still works.'));

            root.addEventListener('click', (event) => {
                if (event.target.closest('[data-chat-retry]')) {
                    event.preventDefault();
                    try {
                        pusher.disconnect();
                    } catch (_) {}
                    try {
                        pusher.connect();
                    } catch (_) {}
                }
            });
        }

        // If the socket is already up, reconcile anything posted since render.
        setTimeout(() => catchUp(root), 1500);

        if (!form || !field) {
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const body = field.value.trim();
            if (body === '') {
                return;
            }

            const clientKey = (window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`).toString();
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;

            try {
                const data = await postJson(root.getAttribute('data-send-url'), { body, client_key: clientKey }, socketHeaders());
                appendMessage(root, data.message);
                field.value = '';
                try {
                    window.localStorage.removeItem('composer:' + draftKey);
                } catch (_) {}
                markRead(root);
            } catch (error) {
                queueOutbox(root, body, clientKey);
                setStatus(root, 'error', `Could not send: ${escapeHtml(error.message)} The message is kept above with a Retry button.`);
            } finally {
                button.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-chat-room]').forEach(initRoom);
    });
})();
