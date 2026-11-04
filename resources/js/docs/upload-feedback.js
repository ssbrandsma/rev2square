// Photo-upload feedback for the documentation and story editors.
// Progressive enhancement over a plain multipart form: client-side
// selection checks before anything is submitted, then a fetch upload
// with per-file results and no page reload — the draft never moves.
// Without fetch, the form submits normally and the server redirects
// back with the draft and a named error bag beside the form.
import { formatBytes, validateUploadSelection } from './text-utils.js';

function limits(form) {
    return {
        maxFiles: Number(form.getAttribute('data-max-files') || 10),
        maxBytes: Number(form.getAttribute('data-max-bytes') || 12582912),
        totalBytes: Number(form.getAttribute('data-total-bytes') || 0),
        extensions: (form.getAttribute('data-extensions') || 'jpg,jpeg,png,webp').split(','),
        typeLabel: form.getAttribute('data-type-label') || 'JPEG, PNG, or WebP',
    };
}

function clearBox(box) {
    if (box) {
        box.replaceChildren();
        box.classList.add('hidden');
    }
}

function showErrors(form, messages) {
    const box = form.querySelector('[data-upload-errors]');
    if (!box) {
        return;
    }
    box.replaceChildren();
    if (messages.length === 0) {
        box.classList.add('hidden');
        return;
    }
    box.classList.remove('hidden');
    const list = document.createElement('ul');
    list.className = 'list-disc space-y-1 pl-5';
    messages.forEach(function (message) {
        const item = document.createElement('li');
        item.textContent = message;
        list.appendChild(item);
    });
    box.appendChild(list);
}

function showResult(form, kind, message, details) {
    const box = form.querySelector('[data-upload-result]');
    if (!box) {
        return;
    }
    box.replaceChildren();
    box.classList.remove('hidden');
    box.className = 'mt-3 rounded-xl border px-4 py-3 text-sm ' + (kind === 'error'
        ? 'border-red-200 bg-red-50 text-red-900'
        : (kind === 'warn'
            ? 'border-amber-200 bg-amber-50 text-amber-900'
            : 'border-emerald-200 bg-emerald-50 text-emerald-900'));
    const text = document.createElement('p');
    text.textContent = message;
    box.appendChild(text);
    (details || []).forEach(function (line) {
        const extra = document.createElement('p');
        extra.className = 'mt-1';
        extra.textContent = line;
        box.appendChild(extra);
    });
}

// Copy the in-progress page draft into the upload form's hidden
// fields so the navigating fallback never loses unsaved work.
function copyDraft(form) {
    const mainSelector = form.getAttribute('data-main-form') || '';
    form.querySelectorAll('[data-draft-field]').forEach(function (hidden) {
        const name = hidden.getAttribute('data-draft-field');
        const field = mainSelector
            ? document.querySelector(mainSelector + ' [name="' + name + '"]')
            : document.querySelector('[name="' + name + '"]');
        hidden.value = field ? field.value : '';
    });
    const input = form.querySelector('input[type="file"]');
    const expected = form.querySelector('[data-expected-count]');
    if (expected) {
        expected.value = input && input.files ? String(input.files.length) : '0';
    }
}

async function readJson(response) {
    try {
        return await response.json();
    } catch (_) {
        return null;
    }
}

function rejectedLines(payload) {
    const lines = [];
    (payload.rejected || []).forEach(function (row) {
        lines.push((row.name || 'file') + ': ' + (row.error || 'rejected'));
    });
    return lines;
}

async function submitFetch(form, input, button) {
    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Uploading…';
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        });
        if (response.status === 413) {
            showResult(form, 'error', 'The server rejected this upload as too large (HTTP 413). Split your selection into smaller batches and try again.');
            return;
        }
        if (response.status === 419) {
            showResult(form, 'error', 'Your session expired, so nothing was uploaded. Your draft is intact — sign in again in another tab, then press Upload to retry.');
            return;
        }
        const payload = await readJson(response);
        if (!payload) {
            showResult(form, 'error', 'The upload failed (HTTP ' + response.status + ') and the result is unknown — nothing is claimed as stored. Check the media library, then try again.');
            return;
        }
        if (!response.ok || payload.ok === false) {
            const lines = rejectedLines(payload);
            Object.keys(payload.errors || {}).forEach(function (key) {
                (payload.errors[key] || []).forEach(function (message) {
                    lines.push(message);
                });
            });
            showResult(form, 'error', payload.message || 'The upload failed. Nothing was stored — try again.', lines);
            return;
        }
        const fresh = (payload.uploaded || []).concat(payload.reused || []).filter(function (img) { return img && typeof img.id !== 'undefined'; });
        if (fresh.length > 0) {
            document.dispatchEvent(new CustomEvent('doc-images-added', { detail: { images: fresh } }));
        }
        const lines = rejectedLines(payload);
        showResult(form, lines.length > 0 ? 'warn' : 'ok', payload.message || 'Upload finished.', lines);
        input.value = '';
        showErrors(form, []);
    } catch (_) {
        showResult(form, 'error', 'The upload was interrupted before it completed. Nothing is claimed as stored — check the media library, then try again.');
    } finally {
        button.disabled = false;
        button.textContent = originalLabel;
    }
}

function initForm(form) {
    const input = form.querySelector('input[type="file"]');
    const button = form.querySelector('button[type="submit"]');
    if (!input || !button) {
        return;
    }

    input.addEventListener('change', function () {
        const checked = validateUploadSelection(input.files, limits(form));
        showErrors(form, checked.errors);
    });

    form.addEventListener('submit', function (event) {
        const checked = validateUploadSelection(input.files, limits(form));
        if (!checked.ok) {
            event.preventDefault();
            showErrors(form, checked.errors);
            // The generic double-submit guard already disabled the
            // button for this submit: the form stays put, so the
            // button must stay usable for the corrected attempt.
            button.disabled = false;
            button.classList.remove('opacity-50');
            const box = form.querySelector('[data-upload-errors]');
            if (box) {
                box.setAttribute('tabindex', '-1');
                box.focus({ preventScroll: false });
            }
            return;
        }
        showErrors(form, []);
        copyDraft(form);
        if (!window.fetch || !window.FormData) {
            window.dispatchEvent(new Event('doc-draft-upload-start'));
            return;
        }
        event.preventDefault();
        submitFetch(form, input, button);
    });
}

export function initUploadFeedback(scope) {
    scope.querySelectorAll('form[data-upload-feedback]').forEach(initForm);
}
