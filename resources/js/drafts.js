// Per-account composer drafts. Draft keys are scoped by user id so two
// sequential logins in one browser never see each other's unsent text.
//
// Legacy unscoped keys (written before this fix) cannot be attributed to
// any account, so signed-in sessions remove them on sight instead of
// restoring them, and logout clears every composer key. No imports: this
// module must stay dependency-free for tests/js/drafts.test.mjs.

export function scopedKey(userId, key) {
    return `composer:${userId}:${key}`;
}

export function legacyKey(key) {
    return `composer:${key}`;
}

function knownUser(userId) {
    return userId !== null && userId !== undefined && userId !== '';
}

/**
 * @returns {string|null} The account's draft, or null when absent/guest.
 */
export function loadDraft(storage, userId, key) {
    if (!knownUser(userId)) {
        return null;
    }

    try {
        const scoped = storage.getItem(scopedKey(userId, key));
        if (scoped) {
            return scoped;
        }

        // Someone's pre-fix draft, unattributable: drop it rather than
        // risk restoring another account's text after a login switch.
        storage.removeItem(legacyKey(key));
    } catch (_) {
        // Private browsing: drafts simply do not persist.
    }

    return null;
}

export function saveDraft(storage, userId, key, value) {
    if (!knownUser(userId)) {
        return;
    }

    try {
        if (value === '') {
            storage.removeItem(scopedKey(userId, key));
        } else {
            storage.setItem(scopedKey(userId, key), value);
        }
    } catch (_) {}
}

export function clearDraft(storage, userId, key) {
    if (!knownUser(userId)) {
        return;
    }

    try {
        storage.removeItem(scopedKey(userId, key));
        storage.removeItem(legacyKey(key));
    } catch (_) {}
}

/** Logout hygiene: remove every composer key, scoped or legacy. */
export function clearAllDrafts(storage) {
    try {
        const doomed = [];
        for (let i = 0; i < storage.length; i += 1) {
            const key = storage.key(i);
            if (key && key.startsWith('composer:')) {
                doomed.push(key);
            }
        }
        doomed.forEach((key) => storage.removeItem(key));
    } catch (_) {}
}
