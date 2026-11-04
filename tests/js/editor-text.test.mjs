// Editor text helpers: server-compatible counting, counter levels,
// auto-height math, and client-side upload selection checks.
import assert from 'node:assert/strict';
import {
    autoHeightPx,
    countChars,
    counterLevel,
    formatBytes,
    formatCount,
    uploadMismatchMessage,
    validateUploadSelection,
} from '../../resources/js/docs/text-utils.js';

const LIMITS = { maxFiles: 10, maxBytes: 12 * 1024 * 1024, totalBytes: 80 * 1024 * 1024, extensions: ['jpg', 'jpeg', 'png', 'webp'], typeLabel: 'JPEG, PNG, or WebP' };

function file(name, size, type = 'image/jpeg') {
    return { name, size, type };
}

// 1. Control characters are stripped before counting, like PHP.
{
    assert.equal(countChars('a\x00b\x07c'), 3);
    assert.equal(countChars('a\tb\nc'), 5);
    assert.equal(countChars('a\x7fb'), 2);
    console.log('PASS control characters are stripped before counting');
}

// 2. Unicode counts code points, matching mb_strlen.
{
    assert.equal(countChars('🛠️x'), 3);
    assert.equal(countChars('é'.normalize('NFC')), 1);
    assert.equal(countChars('é'.normalize('NFD')), 2);
    assert.equal(countChars('  hi  ', { trim: true }), 2);
    assert.equal(countChars('  hi  '), 6);
    console.log('PASS unicode counts code points like the server');
}

// 3. Counter levels flip at 90% and at the limit.
{
    assert.equal(counterLevel(4500, 5000), 'ok');
    assert.equal(counterLevel(4501, 5000), 'warn');
    assert.equal(counterLevel(5000, 5000), 'warn');
    assert.equal(counterLevel(5001, 5000), 'over');
    assert.equal(formatCount(5652), '5,652');
    console.log('PASS counter levels flip at 90% and at the limit');
}

// 4. Auto height clamps content between rows-minimum and viewport max.
{
    assert.equal(autoHeightPx(900, 400, 60), 400);
    assert.equal(autoHeightPx(40, 400, 60), 60);
    assert.equal(autoHeightPx(200, 400, 60), 200);
    console.log('PASS auto height clamps content height');
}

// 5. Twenty-three selected photos produce the exact batching message.
{
    const files = Array.from({ length: 23 }, (_, i) => file(`board-${i}.jpg`, 500000));
    const checked = validateUploadSelection(files, LIMITS);
    assert.equal(checked.ok, false);
    assert.equal(checked.errors[0], 'You selected 23 photos. Upload up to 10 photos at a time. Split your selection into smaller batches.');
    assert.equal(checked.expectedCount, 23);
    console.log('PASS 23 selected photos produce the batching message');
}

// 6. Eleven files fail while ten pass.
{
    const eleven = Array.from({ length: 11 }, (_, i) => file(`b${i}.jpg`, 1000));
    const ten = Array.from({ length: 10 }, (_, i) => file(`b${i}.jpg`, 1000));
    assert.equal(validateUploadSelection(eleven, LIMITS).ok, false);
    assert.equal(validateUploadSelection(ten, LIMITS).ok, true);
    console.log('PASS eleven files fail while ten pass');
}

// 7. Oversized, wrong-format, and over-total selections each explain.
{
    const big = validateUploadSelection([file('huge.jpg', 13 * 1024 * 1024)], LIMITS);
    assert.equal(big.ok, false);
    assert.match(big.errors[0], /huge\.jpg is 13 MB — the limit is 12 MB per photo\./);

    const bad = validateUploadSelection([file('notes.txt', 100, 'text/plain')], LIMITS);
    assert.equal(bad.ok, false);
    assert.match(bad.errors[0], /notes\.txt is not a supported format/);

    const total = validateUploadSelection(
        Array.from({ length: 10 }, (_, i) => file(`b${i}.jpg`, 10 * 1024 * 1024)), LIMITS
    );
    assert.equal(total.ok, false);
    assert.match(total.errors[0], /totals 100 MB — the server accepts at most 80 MB per request/);
    console.log('PASS per-file and total problems each explain themselves');
}

// 8. Mismatched sent/received counts warn about truncation; a zero
// expected count (plain fallback submit) compares nothing.
{
    assert.equal(
        uploadMismatchMessage(23, 20),
        'You selected 23 photos but the server received 20. ' +
        'The request was truncated by a server limit — split your selection into smaller batches and try again. ' +
        'Only the received files below were considered.'
    );
    assert.equal(uploadMismatchMessage(10, 10), null);
    assert.equal(uploadMismatchMessage(0, 0), null);
    console.log('PASS sent/received mismatches warn about truncation');
}

// 9. Byte formatting stays short and readable.
{
    assert.equal(formatBytes(500), '500 B');
    assert.equal(formatBytes(2048), '2 KB');
    assert.equal(formatBytes(12 * 1024 * 1024), '12 MB');
    console.log('PASS byte formatting stays readable');
}
