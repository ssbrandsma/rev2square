// P2-5: opted-in members register on the global online channel from every
// app page. Exercises the real registration helper with a fake document
// and fake echo ports.
import assert from 'node:assert/strict';
import { registerGlobalPresence } from '../../resources/js/presence.js';
import { FakeDocument } from './fake-dom.mjs';

function ports(resolveTo = null) {
    const calls = { join: [], leave: [], track: [], untrack: [], listeners: {}, errors: [], resolved: [] };
    return {
        calls,
        join: (name) => {
            calls.join.push(name);
            return {
                listen: (event, cb) => {
                    calls.listeners[event] = cb;
                },
                onError: (cb) => {
                    calls.errors.push(cb);
                },
            };
        },
        leave: (name) => calls.leave.push(name),
        track: (name) => calls.track.push(name),
        untrack: (name) => calls.untrack.push(name),
        resolve: async (url) => {
            calls.resolved.push(url);
            return resolveTo;
        },
    };
}

function registerElement(doc, channel, channelsUrl = 'https://example.test/realtime/channels?room=online') {
    const element = doc.createElement('div');
    element.setAttribute('data-presence-register', '');
    element.setAttribute('data-channel', channel);
    element.setAttribute('data-channels-url', channelsUrl);
    doc.appendRoot(element);
    return element;
}

function rosterElement(doc, channel) {
    const element = doc.createElement('div');
    element.setAttribute('data-presence-roster', '');
    element.setAttribute('data-channel', channel);
    doc.appendRoot(element);
    return element;
}

// 1. A bare page registers the tab on the global channel.
{
    const doc = new FakeDocument();
    registerElement(doc, 'presence-online.0');
    const echo = ports();

    const joined = registerGlobalPresence(doc, echo);

    assert.deepEqual(joined, ['online.0']);
    assert.deepEqual(echo.calls.join, ['online.0']);
    assert.deepEqual(echo.calls.track, ['online.0']);
    console.log('PASS bare pages join the global channel');
}

// 2. A page roster for the same channel already covers the tab.
{
    const doc = new FakeDocument();
    registerElement(doc, 'presence-online.0');
    rosterElement(doc, 'presence-online.0');
    const echo = ports();

    assert.deepEqual(registerGlobalPresence(doc, echo), []);
    assert.deepEqual(echo.calls.join, []);
    console.log('PASS roster pages skip the duplicate join');
}

// 3. A roster for another channel does not suppress registration.
{
    const doc = new FakeDocument();
    registerElement(doc, 'presence-online.0');
    rosterElement(doc, 'presence-roster.lounge.0');
    const echo = ports();

    assert.deepEqual(registerGlobalPresence(doc, echo), ['online.0']);
    console.log('PASS unrelated rosters do not suppress registration');
}

// 4. Rotation rejoins under the new name and untracks the old one.
{
    const doc = new FakeDocument();
    registerElement(doc, 'presence-online.0');
    const echo = ports();

    registerGlobalPresence(doc, echo);
    assert.ok(echo.calls.listeners['.channel.rotated']);

    echo.calls.listeners['.channel.rotated']({ channel: 'online.1' });

    assert.deepEqual(echo.calls.leave, ['online.0']);
    assert.deepEqual(echo.calls.untrack, ['online.0']);
    assert.deepEqual(echo.calls.join, ['online.0', 'online.1']);
    assert.deepEqual(echo.calls.track, ['online.0', 'online.1']);
    console.log('PASS registration follows rotation');
}

// 5. Same-name announcements are ignored (no join churn).
{
    const doc = new FakeDocument();
    registerElement(doc, 'presence-online.0');
    const echo = ports();

    registerGlobalPresence(doc, echo);
    echo.calls.listeners['.channel.rotated']({ channel: 'online.0' });

    assert.deepEqual(echo.calls.join, ['online.0']);
    assert.deepEqual(echo.calls.leave, []);
    console.log('PASS same-name rotation is ignored');
}

// 6. A missed rotation while offline rejoins via discovery on auth error.
{
    const doc = new FakeDocument();
    registerElement(doc, 'presence-online.0');
    const echo = ports('online.1');

    registerGlobalPresence(doc, echo);
    assert.equal(echo.calls.errors.length, 1);

    await echo.calls.errors[0]();
    await Promise.resolve();

    assert.deepEqual(echo.calls.resolved, ['https://example.test/realtime/channels?room=online']);
    assert.deepEqual(echo.calls.leave, ['online.0']);
    assert.deepEqual(echo.calls.join, ['online.0', 'online.1']);
    console.log('PASS missed rotation rejoins via discovery');
}

// 7. Denied or unresolvable discovery leaves registration alone.
{
    for (const resolveTo of [null]) {
        const doc = new FakeDocument();
        registerElement(doc, 'presence-online.0');
        const echo = ports(resolveTo);

        registerGlobalPresence(doc, echo);
        await echo.calls.errors[0]();
        await Promise.resolve();

        assert.deepEqual(echo.calls.join, ['online.0']);
        assert.deepEqual(echo.calls.leave, []);
    }
    console.log('PASS denied discovery does not churn');
}

console.log('presence.test.mjs: all assertions passed');
