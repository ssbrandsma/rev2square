import { currentUserId, realtime } from '../echo.js';
import { buildMessageNode } from '../chat-render.js';
import { createCatchUp } from '../chat-sync.js';
import { clearDraft, loadDraft, saveDraft } from '../drafts.js';

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

        // Author-controlled strings never pass through HTML parsing: the
        // builder assigns them via textContent/setAttribute (P1-1). Viewer
        // context selects the same Retract/Report/Remove controls the
        // server renders, with authorization enforced on POST (P2-6).
        scroll.appendChild(buildMessageNode(document, payload, {
            id: currentUserId(),
            csrf: csrf(),
            canModerate: root.getAttribute('data-can-moderate') === '1',
            roomKind: root.getAttribute('data-room-kind') === 'project' ? 'project' : 'lounge',
        }));

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

    function initRoom(root) {
        const scroll = root.querySelector('[data-chat-scroll]');
        scrollToBottom(scroll);

        const form = root.querySelector('[data-chat-form]');
        const field = form?.querySelector('textarea[name="body"]');
        const draftKey = form?.getAttribute('data-draft-key');

        // Draft for the chat composer, namespaced by account (P1-3).
        if (form && field && draftKey) {
            const draftUserId = currentUserId();
            const draft = loadDraft(window.localStorage, draftUserId, draftKey);
            if (draft && field.value === '') {
                field.value = draft;
            }

            field.addEventListener('input', () => {
                saveDraft(window.localStorage, draftUserId, draftKey, field.value);
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

        // Reconnect reconciliation drains every history page with a
        // contiguous cursor; live arrivals mid-catch-up are buffered so
        // nothing is skipped or duplicated (P2-4).
        const sync = createCatchUp({
            fetchPage: async (after, limit) => {
                try {
                    const url = `${root.getAttribute('data-history-url')}?after_id=${after}&limit=${limit}`;
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) {
                        return null;
                    }
                    const data = await response.json();
                    return data.messages ?? [];
                } catch (_) {
                    return null;
                }
            },
            append: (message) => appendMessage(root, message),
            isPresent: (id) => root.querySelector(`[data-chat-message][data-message-id="${id}"]`) !== null,
        });

        async function catchUp() {
            const result = await sync.run(lastMessageId(root));
            if (result.started && !result.exhausted && !result.failed) {
                setStatus(root, 'warn', 'A very long backlog was truncated to recent messages. <a href="" class="font-semibold underline">Refresh</a> for the full history.');
            }
            markRead(root);
        }

        // Typing state survives channel rotation: one shared roster, with
        // the whisper binding moved to each new channel.
        const typingLabel = root.querySelector('[data-chat-typing]');
        const typists = new Map();

        const renderTypists = () => {
            if (!typingLabel) {
                return;
            }
            const now = Date.now();
            const names = [];
            typists.forEach((at, name) => {
                if (now - at < 4000) {
                    names.push(name);
                }
            });

            if (names.length === 0) {
                typingLabel.classList.add('hidden');
                typingLabel.textContent = '';
                return;
            }

            typingLabel.classList.remove('hidden');
            typingLabel.textContent = names.length === 1
                ? `${names[0]} is typing…`
                : `${names.slice(0, 3).join(', ')} are typing…`;
        };

        setInterval(renderTypists, 1500);

        let channelName = root.getAttribute('data-channel');
        let channel = null;
        let lastTypingSent = 0;

        const announceTyping = () => {
            const now = Date.now();
            if (now - lastTypingSent < 3000 || !channel) {
                return;
            }
            lastTypingSent = now;
            try {
                channel.whisper('typing', { id: currentUserId(), name: root.getAttribute('data-user-name') });
            } catch (_) {}
        };

        field?.addEventListener('input', announceTyping);

        // Rotation: the server renames channels when access changes and
        // announces the new name on the old channel. Rejoining re-runs
        // channel auth, so viewers that lost access are stranded without
        // receiving anything further (P1-2).
        function subscribe(name) {
            if (channel) {
                try {
                    echo.leave(channelName);
                } catch (_) {}
            }

            channelName = name;
            root.setAttribute('data-channel', name);
            channel = echo.private(name.replace(/^private-/, ''));

            channel.listen('.message.sent', (payload) => {
                if (!sync.bufferLive(payload)) {
                    appendMessage(root, payload);
                    markRead(root);
                }
            });

            channel.listen('.channel.rotated', (event) => {
                if (event && typeof event.channel === 'string' && event.channel !== channelName) {
                    subscribe(event.channel);
                    catchUp();
                }
            });

            channel.listenForWhisper('typing', (event) => {
                if (event.name && event.id !== currentUserId()) {
                    typists.set(event.name, Date.now());
                    renderTypists();
                }
            });

            channel.error(() => {
                form?.classList.add('hidden');
                setStatus(root, 'error', 'You no longer have access to this conversation. <a href="" class="font-semibold underline">Refresh</a> to re-check.');
            });
        }

        subscribe(channelName);

        const pusher = echo.connector.pusher;
        if (pusher?.connection) {
            pusher.connection.bind('connecting', () => setStatus(root, 'warn', 'Connecting to live chat…'));
            pusher.connection.bind('connected', () => {
                setStatus(root, null);
                catchUp();
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
        setTimeout(() => catchUp(), 1500);

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
                clearDraft(window.localStorage, currentUserId(), draftKey);
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
