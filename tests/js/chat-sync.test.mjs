// Catch-up reconciliation: pages drain to exhaustion, live arrivals merge
// without loss or duplication, and retries resume from the contiguous
// cursor — never from the highest rendered id. The fake container inserts
// through the REAL sortedIndex helper, so ordering is proven, not assumed.
import assert from 'node:assert/strict';
import { createCatchUp, sortedIndex } from '../../resources/js/chat-sync.js';

// Fake rendered list mirroring the room's ordered insertion.
function container() {
    const ids = [];
    const present = new Set();
    return {
        ids,
        append: (message) => {
            ids.splice(sortedIndex(ids, message.id), 0, message.id);
            present.add(message.id);
        },
        isPresent: (id) => present.has(id),
    };
}

function server(total, from = 1) {
    return Array.from({ length: total }, (_, i) => ({ id: from + i }));
}

// 0. The insertion helper itself: upper-bound placement.
{
    assert.equal(sortedIndex([], 5), 0);
    assert.equal(sortedIndex([1, 2, 3], 4), 3);
    assert.equal(sortedIndex([1, 2, 3], 0), 0);
    assert.equal(sortedIndex([1, 3, 4], 2), 1);
    assert.equal(sortedIndex([1, 2, 5, 6], 5), 3);
    console.log('PASS sortedIndex places by id order');
}

// 1. The 150-message backlog with a live arrival mid-catch-up.
{
    const view = container();
    const messages = server(150);
    const cursors = [];
    let liveHook = null;

    const sync = createCatchUp({
        limit: 100,
        fetchPage: async (after, pageLimit) => {
            cursors.push(after);
            const page = messages.filter((m) => m.id > after).slice(0, pageLimit);
            if (liveHook) {
                liveHook();
                liveHook = null;
            }
            return page;
        },
        append: view.append,
        isPresent: view.isPresent,
    });
    liveHook = () => assert.equal(sync.bufferLive({ id: 151 }), true);

    const result = await sync.run(0);

    assert.deepEqual(cursors, [0, 100]);
    assert.deepEqual(view.ids, Array.from({ length: 151 }, (_, i) => i + 1));
    assert.equal(result.received, 150);
    assert.equal(result.drained, 1);
    assert.equal(result.exhausted, true);
    assert.equal(result.failed, false);
    assert.equal(sync.getCursor(), 150);
    console.log('PASS 150-message backlog with live arrival reconciles in order');
}

// 2. The review's exact repro: failed second page + newer live event,
// then retry. First run renders [1,2,5] but keeps cursor 2; retry from
// the cursor yields every id exactly once, in order.
{
    const view = container();
    let failSecond = true;
    let liveHook = null;

    const sync = createCatchUp({
        limit: 2,
        fetchPage: async (after, pageLimit) => {
            if (after === 0) {
                const page = [{ id: 1 }, { id: 2 }];
                if (liveHook) {
                    liveHook();
                    liveHook = null;
                }
                return page;
            }
            if (failSecond) {
                return null;
            }
            return [{ id: 3 }, { id: 4 }, { id: 5 }].filter((m) => m.id > after).slice(0, pageLimit);
        },
        append: view.append,
        isPresent: view.isPresent,
    });
    liveHook = () => assert.equal(sync.bufferLive({ id: 5 }), true);

    const first = await sync.run(0);
    assert.equal(first.failed, true);
    assert.deepEqual(view.ids, [1, 2, 5]);
    assert.equal(sync.getCursor(), 2);

    failSecond = false;
    const retry = await sync.run(5);
    assert.equal(retry.failed, false);
    assert.equal(retry.exhausted, true);
    assert.deepEqual(view.ids, [1, 2, 3, 4, 5]);
    assert.equal(sync.getCursor(), 5);
    console.log('PASS failed page + live event + retry loses nothing');
}

// 3. Boundary: exactly one full page still probes for more, then stops.
{
    const view = container();
    const messages = server(100);
    const cursors = [];
    const sync = createCatchUp({
        limit: 100,
        fetchPage: async (after, pageLimit) => {
            cursors.push(after);
            return messages.filter((m) => m.id > after).slice(0, pageLimit);
        },
        append: view.append,
        isPresent: view.isPresent,
    });

    const result = await sync.run(0);

    assert.deepEqual(cursors, [0, 100]);
    assert.deepEqual(view.ids, Array.from({ length: 100 }, (_, i) => i + 1));
    assert.equal(result.exhausted, true);
    assert.equal(sync.getCursor(), 100);
    console.log('PASS exact-page boundary probes once more, then stops');
}

// 4. Empty backlog: single probe, nothing rendered.
{
    const view = container();
    const sync = createCatchUp({
        fetchPage: async () => [],
        append: view.append,
        isPresent: view.isPresent,
    });

    const result = await sync.run(42);

    assert.deepEqual(view.ids, []);
    assert.equal(result.exhausted, true);
    assert.equal(sync.getCursor(), 42);
    console.log('PASS empty backlog is a single quiet probe');
}

// 5. Buffered duplicates of paged messages render once.
{
    const view = container();
    let liveHook = null;
    const sync = createCatchUp({
        limit: 100,
        fetchPage: async (after) => {
            if (liveHook) {
                liveHook();
                liveHook = null;
            }
            return after === 0 ? server(10) : [];
        },
        append: view.append,
        isPresent: view.isPresent,
    });
    liveHook = () => {
        assert.equal(sync.bufferLive({ id: 5 }), true);
        assert.equal(sync.bufferLive({ id: 11 }), true);
    };

    await sync.run(0);

    assert.deepEqual(view.ids, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
    console.log('PASS buffered duplicates are deduplicated');
}

// 6. Out-of-order buffered arrivals still land in id order.
{
    const view = container();
    let liveHook = null;
    const sync = createCatchUp({
        limit: 100,
        fetchPage: async (after) => {
            if (liveHook) {
                liveHook();
                liveHook = null;
            }
            return after === 0 ? [{ id: 1 }, { id: 2 }] : [];
        },
        append: view.append,
        isPresent: view.isPresent,
    });
    liveHook = () => {
        assert.equal(sync.bufferLive({ id: 7 }), true);
        assert.equal(sync.bufferLive({ id: 5 }), true);
    };

    await sync.run(0);

    assert.deepEqual(view.ids, [1, 2, 5, 7]);
    console.log('PASS reversed buffered arrivals land in order');
}

// 7. An own message sent during failed reconciliation keeps final order.
{
    const view = container();
    let fail = true;
    const sync = createCatchUp({
        limit: 2,
        fetchPage: async (after, pageLimit) => {
            if (after !== 0) {
                return fail ? null : [{ id: 3 }, { id: 4 }, { id: 5 }].filter((m) => m.id > after).slice(0, pageLimit);
            }
            return [{ id: 1 }, { id: 2 }];
        },
        append: view.append,
        isPresent: view.isPresent,
    });

    const first = await sync.run(0);
    assert.equal(first.failed, true);

    // Own send renders immediately at the end, like the room does.
    view.append({ id: 6 });
    assert.deepEqual(view.ids, [1, 2, 6]);

    fail = false;
    const retry = await sync.run(6);
    assert.equal(retry.exhausted, true);
    assert.deepEqual(view.ids, [1, 2, 3, 4, 5, 6]);
    console.log('PASS own send during failure keeps final order');
}

// 8. Outside catch-up, live messages are not buffered.
{
    const view = container();
    const sync = createCatchUp({
        fetchPage: async () => [],
        append: view.append,
        isPresent: view.isPresent,
    });

    assert.equal(sync.bufferLive({ id: 1 }), false);
    assert.deepEqual(view.ids, []);
    console.log('PASS idle live messages bypass the buffer');
}

// 9. Transport failure aborts quietly, keeps the cursor, stays retryable.
{
    const view = container();
    const sync = createCatchUp({
        fetchPage: async () => null,
        append: () => assert.fail('must not append on failure'),
        isPresent: () => false,
    });

    const result = await sync.run(9);
    assert.equal(result.failed, true);
    assert.equal(result.exhausted, false);
    assert.equal(sync.isCatchingUp(), false);
    assert.equal(sync.getCursor(), 9);
    console.log('PASS transport failure aborts quietly');
}

// 10. The page cap bounds runaway backlogs; retry continues from the
// cursor instead of restarting, so capped runs still make progress.
{
    const view = container();
    let calls = 0;
    const sync = createCatchUp({
        limit: 100,
        maxPages: 3,
        fetchPage: async (after) => {
            calls += 1;
            return Array.from({ length: 100 }, (_, i) => ({ id: after + i + 1 }));
        },
        append: view.append,
        isPresent: view.isPresent,
    });

    const first = await sync.run(0);
    assert.equal(calls, 3);
    assert.equal(view.ids.length, 300);
    assert.equal(first.exhausted, false);
    assert.equal(sync.getCursor(), 300);

    const retry = await sync.run(300);
    assert.equal(calls, 6);
    assert.equal(view.ids.length, 600);
    assert.equal(view.ids[599], 600);
    assert.equal(sync.getCursor(), 600);
    assert.equal(retry.exhausted, false);
    console.log('PASS page cap bounds backlogs yet retries progress');
}

// 11. A second run while one is in flight declines instead of forking.
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

// 12. Buffer overflow drops oldest arrivals without losing them: the
// cursor stays behind, so a retry re-delivers via history.
{
    const view = container();
    let liveHook = null;
    const sync = createCatchUp({
        limit: 100,
        maxBuffered: 2,
        fetchPage: async (after) => {
            if (liveHook) {
                liveHook();
                liveHook = null;
            }
            if (after === 0) {
                return [{ id: 1 }, { id: 2 }];
            }
            // The server persisted everything; the retry re-covers the gap.
            return [{ id: 3 }, { id: 4 }, { id: 5 }];
        },
        append: view.append,
        isPresent: view.isPresent,
    });
    liveHook = () => {
        assert.equal(sync.bufferLive({ id: 3 }), true);
        assert.equal(sync.bufferLive({ id: 4 }), true);
        assert.equal(sync.bufferLive({ id: 5 }), true);
    };

    const first = await sync.run(0);

    // id 3 fell out of the over-full buffer; 4 and 5 merged.
    assert.deepEqual(view.ids, [1, 2, 4, 5]);
    assert.equal(first.drained, 2);
    assert.equal(sync.getCursor(), 2);

    const retry = await sync.run(5);
    assert.equal(retry.exhausted, true);
    assert.deepEqual(view.ids, [1, 2, 3, 4, 5]);
    console.log('PASS buffer overflow recovers via history retry');
}

console.log('chat-sync.test.mjs: all assertions passed');
