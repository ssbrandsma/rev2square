import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Browsers connect through the app's own host: nginx proxies /app/* to the
// Reverb server, so this works for host browsers and container browsers
// alike without per-environment socket URLs.
function socketHost() {
    return import.meta.env.VITE_REVERB_HOST && import.meta.env.VITE_REVERB_HOST.length > 0
        ? import.meta.env.VITE_REVERB_HOST
        : window.location.hostname;
}

function socketPort() {
    if (import.meta.env.VITE_REVERB_PORT && import.meta.env.VITE_REVERB_PORT.length > 0) {
        return Number(import.meta.env.VITE_REVERB_PORT);
    }

    if (window.location.port && window.location.port.length > 0) {
        return Number(window.location.port);
    }

    return window.location.protocol === 'https:' ? 443 : 80;
}

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

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: socketHost(),
        wsPort: socketPort(),
        wssPort: socketPort(),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
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

// Logging out closes the socket immediately so presence drops the member
// at once instead of lingering until the server-side activity timeout.
document.addEventListener('submit', (event) => {
    if (event.target instanceof HTMLFormElement && event.target.hasAttribute('data-logout') && echo) {
        echo.disconnect();
    }
});
