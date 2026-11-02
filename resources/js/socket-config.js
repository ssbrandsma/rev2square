// Realtime socket endpoint resolution (dependency-free, unit-tested).
//
// Browsers connect through the app's own host: nginx proxies /app/* to the
// Reverb server, so empty build-time values resolve from the serving page.
// Explicit VITE_REVERB_* values always win (dev .env pins the http scheme).
// Only the public app key is baked into assets at build time; host, port,
// and TLS never are, so one image serves http and https deployments alike.

function configured(value) {
    return typeof value === 'string' && value.length > 0 ? value : null;
}

export function resolveSocketConfig(env, location) {
    const host = configured(env.VITE_REVERB_HOST) ?? location.hostname;

    const rawPort = configured(env.VITE_REVERB_PORT) ?? configured(location.port);
    const port = rawPort !== null ? Number(rawPort) : (location.protocol === 'https:' ? 443 : 80);

    const scheme = configured(env.VITE_REVERB_SCHEME);
    const tls = scheme !== null ? scheme === 'https' : location.protocol === 'https:';

    return { host, port, tls };
}
