// Channel discovery outcomes: current names, genuine denial, and
// transport failure are three distinct results. Exercises the real
// resolver against fake fetch implementations.
import assert from 'node:assert/strict';
import { resolveChannels } from '../../resources/js/channel-refresh.js';

function response({ ok, status, json, throws = false }) {
    return async () => {
        if (throws) {
            throw new Error('network down');
        }
        return {
            ok,
            status,
            json: async () => {
                if (json instanceof Error) {
                    throw json;
                }
                return json;
            },
        };
    };
}

// 1. Authorized rooms resolve both current names.
{
    const result = await resolveChannels(
        response({ ok: true, status: 200, json: { chat: 'chat.project.5.2.1', presence: 'roster.project.5.2.1' } }),
        'https://example.test/realtime/channels?room=project:5',
    );

    assert.deepEqual(result, {
        status: 'ok',
        channels: { chat: 'chat.project.5.2.1', presence: 'roster.project.5.2.1' },
    });
    console.log('PASS authorized rooms resolve current names');
}

// 2. Presence-only rooms resolve a null chat name honestly.
{
    const result = await resolveChannels(
        response({ ok: true, status: 200, json: { chat: null, presence: 'online.1' } }),
        'https://example.test/realtime/channels?room=online',
    );

    assert.deepEqual(result, {
        status: 'ok',
        channels: { chat: null, presence: 'online.1' },
    });
    console.log('PASS presence-only rooms resolve honestly');
}

// 3. 401/403 mean genuinely denied access, not a stale epoch.
{
    for (const status of [401, 403]) {
        const result = await resolveChannels(
            response({ ok: false, status, json: {} }),
            'https://example.test/realtime/channels?room=project:5',
        );
        assert.deepEqual(result, { status: 'denied', channels: null });
    }
    console.log('PASS auth failures report denied');
}

// 4. Server errors, bad payloads, and network faults report failed.
{
    const cases = [
        response({ ok: false, status: 500, json: {} }),
        response({ ok: true, status: 200, json: new Error('bad json') }),
        response({ ok: true, status: 200, json: null }),
        response({ ok: true, status: 200, json: 'chat.lounge.1' }),
        response({ ok: false, status: 0, json: {}, throws: true }),
    ];

    for (const fetchImpl of cases) {
        const result = await resolveChannels(fetchImpl, 'https://example.test/realtime/channels?room=lounge');
        assert.deepEqual(result, { status: 'failed', channels: null });
    }
    console.log('PASS transport faults report failed');
}

console.log('channel-refresh.test.mjs: all assertions passed');
