// Paginated reconnect catch-up with a contiguous sync cursor. Pages are
// drained until a short page (or a bounded page cap), so a backlog larger
// than one page is fully recovered. Live events arriving mid-catch-up are
// buffered and merged after the pages.
//
// The cursor is the key invariant: it advances only through history pages
// that actually arrived, never over buffered live ids. A failed or capped
// run still renders what it has (nothing stays invisibly buffered), but a
// retry resumes from the cursor — never from the highest rendered id — so
// gaps can never be skipped. Every render path inserts by id order, so a
// retried gap lands in its correct position. No imports: dependency-free
// for tests/js/chat-sync.test.mjs.

/**
 * Upper-bound insertion point for `id` in ascending `ids`: the index of
 * the first element greater than `id`. Single source of truth for
 * ordered message insertion, shared by the room renderer and the tests.
 *
 * @param {number[]} ids Ascending rendered message ids.
 * @param {number} id
 * @returns {number}
 */
export function sortedIndex(ids, id) {
    let lo = 0;
    let hi = ids.length;
    while (lo < hi) {
        const mid = (lo + hi) >> 1;
        if (ids[mid] <= id) {
            lo = mid + 1;
        } else {
            hi = mid;
        }
    }
    return lo;
}

/**
 * @param {{
 *   fetchPage: (after: number, limit: number) => Promise<object[]|null>,
 *   append: (message: object) => void,
 *   isPresent: (id: number) => boolean,
 *   limit?: number,
 *   maxPages?: number,
 *   maxBuffered?: number,
 * }} deps fetchPage resolves null on transport failure. append must place
 *   by id order (see sortedIndex); isPresent dedupes re-fetched pages.
 * @returns {{ run: (startAfter: number) => Promise<{started: boolean, received: number, drained: number, exhausted: boolean, failed: boolean}>,
 *   bufferLive: (message: object) => boolean, isCatchingUp: () => boolean, getCursor: () => number|null }}
 */
export function createCatchUp(deps) {
    const limit = deps.limit ?? 100;
    const maxPages = deps.maxPages ?? 20;
    const maxBuffered = deps.maxBuffered ?? 500;

    let catchingUp = false;
    let syncedThrough = null;
    const buffered = [];

    function drain() {
        let drained = 0;
        for (const message of buffered.splice(0)) {
            if (!deps.isPresent(message.id)) {
                deps.append(message);
                drained += 1;
            }
        }
        return drained;
    }

    async function run(startAfter) {
        if (catchingUp) {
            return { started: false, received: 0, drained: 0, exhausted: false, failed: false };
        }

        catchingUp = true;
        if (syncedThrough === null) {
            syncedThrough = startAfter;
        }

        let received = 0;
        let exhausted = false;
        let failed = false;

        try {
            let cursor = syncedThrough;

            for (let page = 0; page < maxPages; page += 1) {
                const messages = await deps.fetchPage(cursor, limit);

                if (messages === null) {
                    failed = true;
                    break;
                }

                if (messages.length === 0) {
                    exhausted = true;
                    break;
                }

                let maxId = cursor;
                for (const message of messages) {
                    if (!deps.isPresent(message.id)) {
                        deps.append(message);
                        received += 1;
                    }
                    if (message.id > maxId) {
                        maxId = message.id;
                    }
                }
                cursor = maxId;
                syncedThrough = maxId;

                if (messages.length < limit) {
                    exhausted = true;
                    break;
                }
            }
        } finally {
            catchingUp = false;
        }

        // Always merge buffered live events (ordered insertion keeps them
        // placed correctly), but never advance the cursor over them: only
        // history pages establish contiguity, so a retry re-covers gaps.
        const drained = drain();

        return { started: true, received, drained, exhausted, failed };
    }

    // Returns true when the message was buffered (caller must not render
    // it), false when no catch-up is running (caller renders immediately).
    // Overflow drops the oldest buffered arrivals; the cursor stays behind
    // them, so a later run re-delivers anything dropped via history.
    function bufferLive(message) {
        if (!catchingUp) {
            return false;
        }
        buffered.push(message);
        while (buffered.length > maxBuffered) {
            buffered.shift();
        }
        return true;
    }

    return {
        run,
        bufferLive,
        isCatchingUp: () => catchingUp,
        getCursor: () => syncedThrough,
    };
}
