// P1-3: composer drafts are namespaced by account. Exercises the real
// draft store against a fake Storage through a sequential-login scenario.
import assert from 'node:assert/strict';
import { clearAllDrafts, clearDraft, legacyKey, loadDraft, saveDraft, scopedKey } from '../../resources/js/drafts.js';

class FakeStorage {
    constructor() {
        this.map = new Map();
    }

    get length() {
        return this.map.size;
    }

    key(index) {
        return [...this.map.keys()][index] ?? null;
    }

    getItem(key) {
        return this.map.has(key) ? this.map.get(key) : null;
    }

    setItem(key, value) {
        this.map.set(key, String(value));
    }

    removeItem(key) {
        this.map.delete(key);
    }
}

// 1. Sequential logins in one browser: B never sees A's draft.
{
    const storage = new FakeStorage();
    saveDraft(storage, 11, 'chat-lounge', 'A unsent hello');

    assert.equal(loadDraft(storage, 11, 'chat-lounge'), 'A unsent hello');
    assert.equal(loadDraft(storage, 22, 'chat-lounge'), null);

    saveDraft(storage, 22, 'chat-lounge', 'B reply');
    assert.equal(loadDraft(storage, 22, 'chat-lounge'), 'B reply');
    assert.equal(loadDraft(storage, 11, 'chat-lounge'), 'A unsent hello');
    assert.equal(storage.getItem(scopedKey(11, 'chat-lounge')), 'A unsent hello');
    assert.equal(storage.getItem(scopedKey(22, 'chat-lounge')), 'B reply');
    console.log('PASS accounts are isolated per conversation');
}

// 2. Legacy unscoped keys are dropped on sight, never restored.
{
    const storage = new FakeStorage();
    storage.setItem(legacyKey('thread-5'), 'pre-fix draft');

    assert.equal(loadDraft(storage, 11, 'thread-5'), null);
    assert.equal(storage.getItem(legacyKey('thread-5')), null);
    console.log('PASS legacy keys are cleared, not adopted');
}

// 3. Empty saves and explicit clears remove the scoped key.
{
    const storage = new FakeStorage();
    saveDraft(storage, 11, 'chat-lounge', 'text');
    saveDraft(storage, 11, 'chat-lounge', '');
    assert.equal(storage.getItem(scopedKey(11, 'chat-lounge')), null);

    saveDraft(storage, 11, 'chat-lounge', 'text');
    clearDraft(storage, 11, 'chat-lounge');
    assert.equal(storage.getItem(scopedKey(11, 'chat-lounge')), null);
    assert.equal(loadDraft(storage, 11, 'chat-lounge'), null);
    console.log('PASS empty save and clear remove the draft');
}

// 4. Logout clears every composer key but leaves other data alone.
{
    const storage = new FakeStorage();
    saveDraft(storage, 11, 'chat-lounge', 'A');
    storage.setItem(legacyKey('thread-5'), 'legacy');
    storage.setItem('unrelated', 'keep');

    clearAllDrafts(storage);

    assert.equal(storage.getItem(scopedKey(11, 'chat-lounge')), null);
    assert.equal(storage.getItem(legacyKey('thread-5')), null);
    assert.equal(storage.getItem('unrelated'), 'keep');
    console.log('PASS logout clears composer keys only');
}

// 5. Guests never read or write drafts.
{
    const storage = new FakeStorage();
    storage.setItem(legacyKey('thread-5'), 'legacy');

    assert.equal(loadDraft(storage, null, 'thread-5'), null);
    saveDraft(storage, null, 'thread-5', 'guest text');
    clearDraft(storage, null, 'thread-5');

    assert.equal(storage.map.size, 1);
    assert.equal(storage.getItem(legacyKey('thread-5')), 'legacy');
    console.log('PASS guests leave storage untouched');
}

console.log('drafts.test.mjs: all assertions passed');
