import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { clearAllDrafts } from './drafts.js';
import { resolveSocketConfig } from './socket-config.js';

window.Pusher = Pusher;

let echo = null;

export function realtime() {
    if (echo) {
        return echo;
    }

    const userId = document.querySelector('meta[name="user-id"]')?.content;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!userId || !csrf) {
        return null;
    }

    const socket = resolveSocketConfig(import.meta.env, window.location);

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: socket.host,
        wsPort: socket.port,
        wssPort: socket.port,
        forceTLS: socket.tls,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
            },
        },
    });

    return echo;
}

export function currentUserId() {
    const id = document.querySelector('meta[name="user-id"]')?.content;
    return id ? Number(id) : null;
}

// Presence channels joined by this tab, so a cross-tab opt-out signal
// can leave all of them at once (P1-2).
const presenceChannels = new Set();

export function trackPresenceChannel(name) {
    presenceChannels.add(name);
}

export function untrackPresenceChannel(name) {
    presenceChannels.delete(name);
}

function signal(key, userId) {
    try {
        window.localStorage.setItem(key, JSON.stringify({ uid: userId, at: Date.now() }));
    } catch (_) {}
}

function leaveAllPresence() {
    if (!echo) {
        return;
    }
    presenceChannels.forEach((name) => {
        try {
            echo.leave(name);
        } catch (_) {}
    });
    presenceChannels.clear();
}

// Logging out closes the socket immediately so presence drops the member
// at once instead of lingering until the server-side activity timeout,
// clears composer drafts so the next login starts clean, and tells other
// tabs of the same account to disconnect too.
document.addEventListener('submit', (event) => {
    if (event.target instanceof HTMLFormElement && event.target.hasAttribute('data-logout')) {
        const userId = currentUserId();
        clearAllDrafts(window.localStorage);
        signal('rt:logout', userId);
        if (echo) {
            echo.disconnect();
        }
    }
});

// Cross-tab realtime signals. Storage events fire only in tabs other
// than the writer, which is exactly the audience: the writing tab
// already acted (logout disconnects it; preference saves reload it).
// Signals are scoped by user id so a later login is never affected.
window.addEventListener('storage', (event) => {
    if (event.key !== 'rt:logout' && event.key !== 'rt:presence-optout') {
        return;
    }

    let data = null;
    try {
        data = JSON.parse(event.newValue ?? 'null');
    } catch (_) {
        return;
    }

    if (!data || data.uid !== currentUserId()) {
        return;
    }

    if (event.key === 'rt:logout') {
        if (echo) {
            echo.disconnect();
        }
        return;
    }

    leaveAllPresence();
});
