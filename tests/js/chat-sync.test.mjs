// P2-4: reconnect catch-up drains every page and keeps server order even
// when live events arrive mid-reconciliation. Exercises the real sync
// helper against a synthetic 150-message backlog with 100-message pages.
import assert from 'node:assert/strict';
import { createCatchUp } from '../../resources/js/chat-sync.js';

function server(total) {
    return Array.from({ length: total }, (_, i) => ({ id: i + 1 }));
}

function harness(total, limit) {
    const messages = server(total);
    const rendered = [];
    const present = new Set();
    const cursors = [];
    let liveHook = null;

    const sync = createCatchUp({
        limit,
        fetchPage: async (after, pageLimit) => {
            cursors.push(after);
            const page = messages.filter((m) => m.id > after).slice(0, pageLimit);
            // A live event landing between pages, as on a real socket.
            if (liveHook) {
                liveHook();
                liveHook = null;
            }
            return page;
        },
        append: (message) => {
            rendered.push(message.id);
            present.add(message.id);
        },
        isPresent: (id) => present.has(id),
    });

    return {
        sync,
        rendered,
        cursors,
        onFirstPage: (fn) => {
            liveHook = fn;
        },
    };
}

// 1. The review's scenario: 150 backlog messages, 100 per page, one live
// arrival mid-catch-up. Every id exactly once, in server order.
{
    const t = harness(150, 100);
    t.onFirstPage(() => {
        assert.equal(t.sync.bufferLive({ id: 151 }), true);
    });

    const result = await t.sync.run(0);

    assert.deepEqual(t.cursors, [0, 100]);
    assert.deepEqual(t.rendered, Array.from({ length: 151 }, (_, i) => i + 1));
    assert.equal(result.received, 150);
    assert.equal(result.exhausted, true);
    assert.equal(result.failed, false);
    console.log('PASS 150-message backlog with live arrival reconciles in order');
}

// 2. Boundary: exactly one full page still probes for more, then stops.
{
    const t = harness(100, 100);
    const result = await t.sync.run(0);

    assert.deepEqual(t.cursors, [0, 100]);
    assert.deepEqual(t.rendered, Array.from({ length: 100 }, (_, i) => i + 1));
    assert.equal(result.exhausted, true);
    console.log('PASS exact-page boundary probes once more, then stops');
}

// 3. Empty backlog: single probe, nothing rendered.
{
    const t = harness(0, 100);
    const result = await t.sync.run(42);

    assert.deepEqual(t.cursors, [42]);
    assert.deepEqual(t.rendered, []);
    assert.equal(result.exhausted, true);
    console.log('PASS empty backlog is a single quiet probe');
}

// 4. Buffered live duplicates of paged messages render once.
{
    const t = harness(10, 100);
    t.onFirstPage(() => {
        assert.equal(t.sync.bufferLive({ id: 5 }), true);
        assert.equal(t.sync.bufferLive({ id: 11 }), true);
    });

    const result = await t.sync.run(0);

    assert.deepEqual(t.rendered, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
    assert.equal(result.exhausted, true);
    console.log('PASS buffered duplicates are deduplicated');
}

// 5b. A second run while one is in flight declines instead of forking.
{
    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });
    const sync = createCatchUp({
        fetchPage: async () => {
            await gate;
            return [];
        },
        append: () => {},
        isPresent: () => false,
    });

    const first = sync.run(0);
    const second = await sync.run(0);
    assert.equal(second.started, false);
    release();
    const done = await first;
    assert.equal(done.started, true);
    assert.equal(done.exhausted, true);
    console.log('PASS overlapping runs decline cleanly');
}

// 5. Outside catch-up, live messages are not buffered.
{
    const t = harness(0, 100);
    assert.equal(t.sync.bufferLive({ id: 1 }), false);
    assert.deepEqual(t.rendered, []);
    console.log('PASS idle live messages bypass the buffer');
}

// 6. Transport failure aborts quietly and reports failed.
{
    const sync = createCatchUp({
        fetchPage: async () => null,
        append: () => assert.fail('must not append on failure'),
        isPresent: () => false,
    });

    const result = await sync.run(0);
    assert.equal(result.failed, true);
    assert.equal(result.exhausted, false);
    assert.equal(sync.isCatchingUp(), false);
    console.log('PASS transport failure aborts quietly');
}

// 7. The page cap bounds runaway backlogs instead of paging forever.
{
    const rendered = [];
    const present = new Set();
    let calls = 0;
    const sync = createCatchUp({
        limit: 100,
        maxPages: 3,
        fetchPage: async (after) => {
            calls += 1;
            return Array.from({ length: 100 }, (_, i) => ({ id: after + i + 1 }));
        },
        append: (message) => {
            rendered.push(message.id);
            present.add(message.id);
        },
        isPresent: (id) => present.has(id),
    });

    const result = await sync.run(0);
    assert.equal(calls, 3);
    assert.equal(rendered.length, 300);
    assert.equal(result.exhausted, false);
    console.log('PASS page cap bounds unbounded backlogs');
}

console.log('chat-sync.test.mjs: all assertions passed');
