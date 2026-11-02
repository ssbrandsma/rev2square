// Paginated reconnect catch-up with a contiguous sync cursor. Pages are
// drained until a short page (or a bounded page cap), so a backlog larger
// than one page is fully recovered. Live events arriving mid-catch-up are
// buffered and appended after the backlog, so every id renders exactly
// once in server order. No imports: dependency-free for tests/js/.

/**
 * @param {{
 *   fetchPage: (after: number, limit: number) => Promise<object[]|null>,
 *   append: (message: object) => void,
 *   isPresent: (id: number) => boolean,
 *   limit?: number,
 *   maxPages?: number,
 * }} deps fetchPage resolves null on transport failure (abort quietly).
 * @returns {{ run: (startAfter: number) => Promise<{started: boolean, received: number, exhausted: boolean, failed: boolean}>,
 *   bufferLive: (message: object) => boolean, isCatchingUp: () => boolean }}
 */
export function createCatchUp(deps) {
    const limit = deps.limit ?? 100;
    const maxPages = deps.maxPages ?? 20;

    let catchingUp = false;
    const buffered = [];

    function drain() {
        for (const message of buffered.splice(0)) {
            if (!deps.isPresent(message.id)) {
                deps.append(message);
            }
        }
    }

    async function run(startAfter) {
        if (catchingUp) {
            return { started: false, received: 0, exhausted: false, failed: false };
        }

        catchingUp = true;
        let received = 0;
        let exhausted = false;
        let failed = false;

        try {
            let cursor = startAfter;

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

                if (messages.length < limit) {
                    exhausted = true;
                    break;
                }
            }
        } finally {
            drain();
            catchingUp = false;
        }

        return { started: true, received, exhausted, failed };
    }

    // Returns true when the message was buffered (caller must not render
    // it), false when no catch-up is running (caller renders immediately).
    function bufferLive(message) {
        if (!catchingUp) {
            return false;
        }
        buffered.push(message);
        return true;
    }

    return { run, bufferLive, isCatchingUp: () => catchingUp };
}
