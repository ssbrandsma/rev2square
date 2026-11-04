// Shared editor text helpers. DOM-free and unit-testable (see
// tests/js/editor-text.test.mjs); story-editor.js and the upload
// feedback module consume these so client behavior matches the
// server validators exactly.
const CONTROL_CHARS = /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g;

// Server-compatible character count: strip the same control range
// PHP removes (DocDocument/StoryDocument keep tab/newline), then
// count Unicode code points like mb_strlen. Short fields also trim,
// mirroring cleanShortDetailed.
export function countChars(value, { trim = false } = {}) {
    let s = String(value ?? '').replace(CONTROL_CHARS, '');
    if (trim) {
        s = s.trim();
    }
    return Array.from(s).length;
}

export function formatCount(n) {
    return Number(n).toLocaleString('en-US');
}

// Counter level: roomy, near the limit (>90%), or over it.
export function counterLevel(len, max) {
    if (len > max) {
        return 'over';
    }
    if (len > max * 0.9) {
        return 'warn';
    }
    return 'ok';
}

// Auto-grow height: content height clamped between the rows-derived
// minimum and the viewport-relative maximum. Never shrinks a box
// the user resized by hand — callers check manual heights first.
export function autoHeightPx(scrollHeight, maxPx, minPx) {
    return Math.min(Math.max(Math.ceil(scrollHeight), minPx), maxPx);
}

// Client-side upload selection check. Files are {name, size, type}
// likes (File objects qualify). Returns every problem found so the
// form can list them before anything is submitted; the server
// re-validates authoritatively.
export function validateUploadSelection(files, limits) {
    const list = Array.from(files || []);
    const errors = [];
    const maxFiles = limits.maxFiles;
    const maxBytes = limits.maxBytes;
    const totalBytes = limits.totalBytes;
    const extensions = (limits.extensions || []).map((e) => String(e).toLowerCase());

    if (list.length > maxFiles) {
        errors.push(
            `You selected ${list.length} photos. Upload up to ${maxFiles} photos at a time. Split your selection into smaller batches.`
        );
    }

    let total = 0;
    list.forEach((file) => {
        const name = file.name || 'file';
        total += file.size || 0;
        const ext = name.includes('.') ? name.slice(name.lastIndexOf('.') + 1).toLowerCase() : '';
        if (extensions.length > 0 && !extensions.includes(ext)) {
            errors.push(`${name} is not a supported format. Use ${limits.typeLabel}.`);
        } else if (file.type && !file.type.startsWith('image/')) {
            errors.push(`${name} does not look like an image file. Use ${limits.typeLabel}.`);
        }
        if ((file.size || 0) > maxBytes) {
            errors.push(`${name} is ${formatBytes(file.size)} — the limit is ${formatBytes(maxBytes)} per photo.`);
        }
    });

    if (totalBytes > 0 && total > totalBytes) {
        errors.push(
            `The selection totals ${formatBytes(total)} — the server accepts at most ${formatBytes(totalBytes)} per request. Split it into smaller batches.`
        );
    }

    return { ok: errors.length === 0, errors, expectedCount: list.length, totalBytes: total };
}

export function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n >= 1048576) {
        const mb = n / 1048576;
        return `${mb >= 10 ? Math.round(mb) : Math.round(mb * 10) / 10} MB`;
    }
    if (n >= 1024) {
        return `${Math.round(n / 1024)} KB`;
    }
    return `${n} B`;
}

// PHP truncated the request (max_file_uploads) when fewer files
// arrived than the form sent. expectedCount 0 means the browser did
// not report a count (plain fallback submit): nothing to compare.
export function uploadMismatchMessage(expectedCount, receivedCount) {
    if (!(expectedCount > 0) || expectedCount === receivedCount) {
        return null;
    }
    return (
        `You selected ${expectedCount} photos but the server received ${receivedCount}. ` +
        'The request was truncated by a server limit — split your selection into smaller batches and try again. ' +
        'Only the received files below were considered.'
    );
}
