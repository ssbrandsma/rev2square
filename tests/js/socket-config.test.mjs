// Socket endpoint resolution: explicit VITE_REVERB_* values win, otherwise
// the page itself decides (same host/port, TLS follows the page scheme).
// Exercises the real resolver with fake env/location pairs.
import assert from 'node:assert/strict';
import { resolveSocketConfig } from '../../resources/js/socket-config.js';

const page = (hostname, port, protocol) => ({ hostname, port, protocol });

// 1. Dev parity: empty env on http localhost:8080 stays plain ws.
{
    const result = resolveSocketConfig({}, page('localhost', '8080', 'http:'));

    assert.deepEqual(result, { host: 'localhost', port: 8080, tls: false });
    console.log('PASS empty env on http localhost keeps ws port 8080');
}

// 2. Prod fix: empty env on an https page upgrades over wss on 443.
{
    const result = resolveSocketConfig({}, page('rev2square.example.com', '', 'https:'));

    assert.deepEqual(result, { host: 'rev2square.example.com', port: 443, tls: true });
    console.log('PASS empty env on https page uses wss port 443');
}

// 3. Plain http without an explicit port defaults to 80, no TLS.
{
    const result = resolveSocketConfig({}, page('example.com', '', 'http:'));

    assert.deepEqual(result, { host: 'example.com', port: 80, tls: false });
    console.log('PASS http page without port uses ws port 80');
}

// 4. An explicit https scheme wins even on an http page.
{
    const result = resolveSocketConfig(
        { VITE_REVERB_SCHEME: 'https' },
        page('localhost', '8080', 'http:'),
    );

    assert.deepEqual(result, { host: 'localhost', port: 8080, tls: true });
    console.log('PASS explicit https scheme forces TLS on http page');
}

// 5. An explicit http scheme wins even on an https page (dev .env parity).
{
    const result = resolveSocketConfig(
        { VITE_REVERB_SCHEME: 'http' },
        page('rev2square.example.com', '', 'https:'),
    );

    assert.deepEqual(result, { host: 'rev2square.example.com', port: 443, tls: false });
    console.log('PASS explicit http scheme keeps ws on https page');
}

// 6. Explicit host/port win over the page location.
{
    const result = resolveSocketConfig(
        { VITE_REVERB_HOST: 'sockets.example.com', VITE_REVERB_PORT: '8443' },
        page('rev2square.example.com', '', 'https:'),
    );

    assert.deepEqual(result, { host: 'sockets.example.com', port: 8443, tls: true });
    console.log('PASS explicit host and port override the page');
}

// 7. Empty-string env values behave as unset (follow the page).
{
    const result = resolveSocketConfig(
        { VITE_REVERB_HOST: '', VITE_REVERB_PORT: '', VITE_REVERB_SCHEME: '' },
        page('rev2square.example.com', '', 'https:'),
    );

    assert.deepEqual(result, { host: 'rev2square.example.com', port: 443, tls: true });
    console.log('PASS empty-string env values follow the page');
}
