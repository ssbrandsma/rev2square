// Project story block editor: paragraphs, headings, lists, quotes, code,
// and inline images from the project's own media library. Vanilla JS,
// no editor framework: the document is a small validated JSON schema
// (see StoryDocument) rendered into discrete block controls.
//
// Guarantees: undo/redo history, dirty/saving/saved states, unsaved
// changes warning, draft preservation across validation failures (the
// JSON round-trips through old input), and a client-side preview that
// mirrors the server renderer (escape-first; the server re-validates).
import { fromClient } from '../docs/markers.js';
import { autoHeightPx, countChars, counterLevel, formatCount } from '../docs/text-utils.js';
import { currentUserId } from '../echo.js';
import { clearDraft, loadDraft, saveDraft } from '../drafts.js';
import { initUploadFeedback } from '../docs/upload-feedback.js';

(function () {
    'use strict';

    // True while a docs photo upload (which carries the editor draft
    // along) is submitting, so the unsaved-changes warning stays quiet.
    // Set only for the navigating fallback submit; fetch uploads leave
    // it alone so leaving mid-upload still warns about unsaved edits.
    let uploadingDocDraft = false;

    window.addEventListener('doc-draft-upload-start', function () { uploadingDocDraft = true; });
    window.addEventListener('doc-draft-upload-end', function () { uploadingDocDraft = false; });

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function inlinePreview(text) {
        // Mirrors StoryRenderer::inline (subset): escape-first, then
        // bold/italic/code/link patterns. Server output is authoritative.
        let out = '';
        let rest = String(text);
        const patterns = [
            { re: /^\*\*(.+?)\*\*/s, tag: 'strong' },
            { re: /^`(.+?)`/s, tag: 'code' },
        ];
        while (rest !== '') {
            let matched = false;
            for (const p of patterns) {
                const m = rest.match(p.re);
                if (m && m[1].trim() !== '') {
                    out += '<' + p.tag + '>' + esc(m[1]) + '</' + p.tag + '>';
                    rest = rest.slice(m[0].length);
                    matched = true;
                    break;
                }
            }
            if (matched) {
                continue;
            }
            const link = rest.match(/^\[([^\]]+)\]\(([^)\s]+)\)/);
            if (link) {
                if (/^https?:\/\/[^/\s]+\.[^/\s]+/.test(link[2]) && !link[2].includes('@')) {
                    out += '<a href="' + esc(link[2]) + '">' + esc(link[1]) + '</a>';
                } else {
                    out += esc(link[0]);
                }
                rest = rest.slice(link[0].length);
                continue;
            }
            const em = rest.match(/^\*(.+?)\*/s);
            if (em && em[1].trim() !== '') {
                out += '<em>' + esc(em[1]) + '</em>';
                rest = rest.slice(em[0].length);
                continue;
            }
            out += esc(rest.slice(0, 1));
            rest = rest.slice(1);
        }
        return out.replace(/\n/g, '<br>');
    }

    function initEditor(root) {
        const images = JSON.parse(root.getAttribute('data-images') || '[]');
        const imageById = {};
        images.forEach(function (img) { imageById[img.id] = img; });
        const docsPages = JSON.parse(root.getAttribute('data-docs') || '[]');
        const markersEnabled = root.getAttribute('data-markers') === '1';
        const jsonFieldName = root.getAttribute('data-json-field') || 'story_document';

        let doc;
        try {
            doc = JSON.parse(root.getAttribute('data-initial') || '');
            if (!doc || !Array.isArray(doc.blocks)) {
                throw new Error('bad doc');
            }
        } catch (_) {
            doc = { version: 1, blocks: [{ type: 'paragraph', text: '' }] };
        }

        const list = root.querySelector('[data-blocks]');
        const jsonField = root.querySelector('input[name="' + jsonFieldName + '"]');
        const saveButton = root.querySelector('[data-save]');
        const saveState = root.querySelector('[data-save-state]');
        const undoButton = root.querySelector('[data-undo]');
        const redoButton = root.querySelector('[data-redo]');
        const previewPane = root.querySelector('[data-preview]');
        const previewToggle = root.querySelector('[data-preview-toggle]');
        const editorPane = root.querySelector('[data-editor-pane]');
        let history = [JSON.stringify(doc)];
        let future = [];
        let dirty = false;
        let saving = false;

        // Stable block identities: every block carries a client-side
        // key that survives reordering and undo/redo (snapshots keep
        // it), so editing state — heights, focus, selection — follows
        // the block instead of its position. The server allowlists
        // known fields and drops the key on save, so stored documents
        // never change shape.
        let keyCounter = 0;
        function ensureKeys() {
            doc.blocks.forEach(function (block) {
                if (!block || typeof block.key !== 'string' || block.key === '') {
                    keyCounter += 1;
                    block.key = 'b' + keyCounter.toString(36) + Date.now().toString(36);
                }
            });
        }
        ensureKeys();

        // Textarea heights, keyed by stable focus key (block key plus
        // field). Manual resizes win forever; everything else grows
        // with its content up to a viewport-relative maximum.
        const manualHeights = new Map();
        const lastAutoHeights = new Map();
        // Focus key to "index:field", rebuilt each render, so heights
        // persist across page loads by position when keys randomize.
        const fkeyPositions = new Map();
        let restoredHeights = {};
        try {
            const form = root.closest('form');
            const raw = window.sessionStorage.getItem('story-editor-ui:' + (form && form.action ? form.action : window.location.pathname));
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.heights === 'object') {
                    restoredHeights = parsed.heights;
                }
            }
        } catch (_) {
            restoredHeights = {};
        }

        function persistHeights() {
            try {
                const form = root.closest('form');
                const byPosition = {};
                manualHeights.forEach(function (px, fkey) {
                    const pos = fkeyPositions.get(fkey);
                    if (pos) {
                        byPosition[pos] = px;
                    }
                });
                window.sessionStorage.setItem(
                    'story-editor-ui:' + (form && form.action ? form.action : window.location.pathname),
                    JSON.stringify({ heights: byPosition })
                );
            } catch (_) {}
        }

        // Local backup of the whole draft (content + sizing survive a
        // crash or an expired session). Scoped by account, project,
        // and page via the root draft key; restored only through the
        // explicit Restore action below, never automatically.
        const draftKey = root.getAttribute('data-draft-key') || '';
        function backupDraft() {
            if (!draftKey) {
                return;
            }
            saveDraft(window.localStorage, currentUserId(), draftKey, JSON.stringify({ at: Date.now(), json: snapshot() }));
        }
        function clearBackup() {
            if (draftKey) {
                clearDraft(window.localStorage, currentUserId(), draftKey);
            }
        }
        function loadBackup() {
            if (!draftKey) {
                return null;
            }
            const raw = loadDraft(window.localStorage, currentUserId(), draftKey);
            if (!raw) {
                return null;
            }
            try {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.json === 'string' && typeof parsed.at === 'number') {
                    return parsed;
                }
            } catch (_) {}
            clearBackup();
            return null;
        }

        function snapshot() {
            return JSON.stringify(doc);
        }

        function commit() {
            ensureKeys();
            history.push(snapshot());
            if (history.length > 50) {
                history.shift();
            }
            future = [];
            setDirty(true);
            backupDraft();
            persistHeights();
            sync();
        }

        function newBlock(type) {
            keyCounter += 1;
            const block = { type: type, text: '', key: 'b' + keyCounter.toString(36) + Date.now().toString(36) };
            if (type === 'heading') {
                block.level = 2;
            }
            if (type === 'list') {
                block.ordered = false;
                block.items = [''];
                delete block.text;
            }
            if (type === 'step') {
                block.title = '';
                block.text = '';
                block.media_ids = [];
            }
            if (type === 'doc_link') {
                delete block.text;
                block.doc_id = docsPages.length > 0 ? docsPages[0].id : null;
                block.label = '';
            }
            return block;
        }

        function setDirty(value) {
            dirty = value;
            if (saveState) {
                saveState.textContent = saving ? 'Saving…' : (dirty ? 'Unsaved changes' : 'Saved');
            }
            if (saveButton) {
                saveButton.disabled = saving || !dirty;
            }
        }

        function blockLabel(block, index) {
            const labels = {
                paragraph: 'Paragraph', heading: 'Heading', list: 'List',
                quote: 'Quote', code: 'Code', image: 'Image',
                step: 'Step', doc_link: 'Page link',
            };
            return 'Block ' + (index + 1) + ': ' + (labels[block.type] || block.type);
        }

        function labelHead(label, max, getValue, trim) {
            const head = document.createElement('span');
            head.className = 'flex items-baseline justify-between gap-2';
            const name = document.createElement('span');
            name.textContent = label;
            head.appendChild(name);
            const counter = document.createElement('span');
            counter.className = 'editor-counter font-normal tabular-nums text-zinc-400';
            head.appendChild(counter);
            function paint() {
                const len = countChars(getValue(), { trim: Boolean(trim) });
                counter.textContent = formatCount(len) + ' / ' + formatCount(max);
                const level = counterLevel(len, max);
                counter.classList.toggle('editor-counter-warn', level === 'warn');
                counter.classList.toggle('editor-counter-over', level === 'over');
            }
            paint();
            return { head: head, paint: paint };
        }

        function autoSize(area) {
            const fkey = area.getAttribute('data-fkey');
            const manual = fkey ? manualHeights.get(fkey) : undefined;
            if (manual) {
                area.style.height = manual + 'px';
                return;
            }
            const rows = Number(area.getAttribute('data-rows') || 3);
            let lineHeight = parseFloat(window.getComputedStyle(area).lineHeight);
            if (Number.isNaN(lineHeight)) {
                lineHeight = 21;
            }
            const minPx = Math.ceil(lineHeight * rows + 14);
            const maxPx = Math.floor(window.innerHeight * 0.5);
            area.style.height = 'auto';
            const px = autoHeightPx(area.scrollHeight, Math.max(maxPx, minPx), minPx);
            area.style.height = px + 'px';
            if (fkey) {
                lastAutoHeights.set(fkey, px);
            }
        }

        function trackManualResize(area) {
            // A mouse release that leaves a different height than the
            // last automatic sizing is a manual resize: remember it for
            // the session (and across reloads) instead of auto-growing.
            area.addEventListener('mouseup', function () {
                const fkey = area.getAttribute('data-fkey');
                if (!fkey) {
                    return;
                }
                const px = Math.round(area.getBoundingClientRect().height);
                if (px > 0 && px !== lastAutoHeights.get(fkey)) {
                    manualHeights.set(fkey, px);
                    persistHeights();
                    backupDraft();
                }
            });
        }

        function applyRestoredHeight(area, indexField) {
            const fkey = area.getAttribute('data-fkey');
            const px = restoredHeights[indexField];
            if (fkey && !manualHeights.has(fkey) && typeof px === 'number' && px > 0) {
                manualHeights.set(fkey, px);
            }
        }

        function textarea(label, value, opts, onInput) {
            const wrap = document.createElement('label');
            wrap.className = 'block text-xs text-zinc-500';
            const area = document.createElement('textarea');
            area.rows = opts.rows || 3;
            area.setAttribute('data-rows', String(opts.rows || 3));
            area.setAttribute('data-fkey', opts.fkey);
            if (opts.pos) {
                area.setAttribute('data-pos', opts.pos);
                fkeyPositions.set(opts.fkey, opts.pos);
            }
            area.className = 'editor-textarea mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500';
            area.value = value;
            const counter = labelHead(label, opts.max, function () { return area.value; }, false);
            wrap.appendChild(counter.head);
            area.addEventListener('input', function () {
                onInput(area.value);
                counter.paint();
                autoSize(area);
            });
            trackManualResize(area);
            wrap.appendChild(area);
            return wrap;
        }

        function smallButton(label, title, onClick, fkey) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.title = title;
            if (fkey) {
                button.setAttribute('data-fkey', fkey);
            }
            button.className = 'rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50';
            button.addEventListener('click', onClick);
            return button;
        }

        // Single-line fields carry a counter instead of maxlength: pasted
        // text must never be silently cut — the counter warns and the
        // server enforces the same limit.
        function textLine(label, value, max, fkey, onInput, placeholder) {
            const wrap = document.createElement('label');
            wrap.className = 'block text-xs text-zinc-500';
            const input = document.createElement('input');
            input.type = 'text';
            input.setAttribute('data-fkey', fkey);
            input.className = 'mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500';
            input.value = value || '';
            if (placeholder) {
                input.placeholder = placeholder;
            }
            const counter = labelHead(label, max, function () { return input.value; }, true);
            wrap.appendChild(counter.head);
            input.addEventListener('input', function () {
                onInput(input.value);
                counter.paint();
            });
            wrap.appendChild(input);
            return wrap;
        }

        function numberField(label, value, min, max, fkey, onInput) {
            const wrap = document.createElement('label');
            wrap.className = 'block text-xs text-zinc-500';
            wrap.textContent = label;
            const input = document.createElement('input');
            input.type = 'number';
            input.min = String(min);
            input.max = String(max);
            input.setAttribute('data-fkey', fkey);
            input.className = 'mt-1 block w-full rounded-lg border-zinc-300 text-sm';
            input.value = String(value);
            input.addEventListener('change', function () {
                let n = Number(input.value);
                if (Number.isNaN(n)) {
                    n = min;
                }
                n = Math.min(max, Math.max(min, n));
                input.value = String(n);
                onInput(n);
            });
            wrap.appendChild(input);
            return wrap;
        }

        // Client mirror of the server budgets (DocDocument /
        // StoryDocument). Counters and warnings only — the server
        // re-validates authoritatively.
        const LIMITS = { text: 5000, items: 30, title: 200, caption: 500, label: 80, note: 500, markers: 20, stepMedia: 4, blocks: 100 };

        function renderMarkerEditor(block, index) {
            if (!Array.isArray(block.markers)) {
                block.markers = [];
            }
            const wrap = document.createElement('div');
            wrap.className = 'space-y-2 rounded-lg border border-zinc-200 p-2 sm:p-3';
            const head = document.createElement('p');
            head.className = 'text-xs font-semibold text-zinc-500';
            head.textContent = 'Annotation markers (' + block.markers.length + '/' + LIMITS.markers + ') — click the photo to place, or add below and set coordinates';
            wrap.appendChild(head);

            const info = imageById[block.media_id];
            if (info && (info.card_url || info.thumb_url)) {
                const placer = document.createElement('button');
                placer.type = 'button';
                placer.className = 'editor-placer relative block w-full max-w-[560px] overflow-hidden rounded-lg border border-zinc-200 bg-white';
                placer.title = 'Click to place a marker';
                placer.setAttribute('aria-label', 'Place a marker on the photo');
                placer.setAttribute('data-fkey', block.key + ':placer');
                const img = document.createElement('img');
                img.src = info.card_url || info.thumb_url;
                img.alt = '';
                img.className = 'block h-auto w-full';
                img.draggable = false;
                placer.appendChild(img);
                block.markers.forEach(function (marker, n) {
                    const dot = document.createElement('span');
                    dot.className = 'doc-marker';
                    dot.style.left = (marker.x * 100) + '%';
                    dot.style.top = (marker.y * 100) + '%';
                    dot.textContent = String(n + 1);
                    dot.setAttribute('aria-hidden', 'true');
                    placer.appendChild(dot);
                });
                placer.addEventListener('click', function (event) {
                    if (block.markers.length >= LIMITS.markers) {
                        return;
                    }
                    const at = fromClient(img.getBoundingClientRect(), event.clientX, event.clientY);
                    if (at === null) {
                        return;
                    }
                    block.markers.push({ x: at.x, y: at.y, label: '', note: '' });
                    commit();
                });
                wrap.appendChild(placer);
            }

            block.markers.forEach(function (marker, n) {
                const row = document.createElement('div');
                row.className = 'grid grid-cols-[auto_1fr] items-end gap-2 rounded-lg bg-white p-2';
                const badge = document.createElement('span');
                badge.className = 'doc-marker doc-marker-static';
                badge.textContent = String(n + 1);
                badge.setAttribute('aria-hidden', 'true');
                row.appendChild(badge);
                const fields = document.createElement('div');
                fields.className = 'grid gap-2 sm:grid-cols-2 xl:grid-cols-4';
                fields.appendChild(textLine('Label', marker.label || '', LIMITS.label, block.key + ':marker-' + n + '-label', function (v) { marker.label = v; touch(); }));
                fields.appendChild(textLine('Note', marker.note || '', LIMITS.note, block.key + ':marker-' + n + '-note', function (v) { marker.note = v; touch(); }));
                fields.appendChild(numberField('X %', Math.round(marker.x * 100), 0, 100, block.key + ':marker-' + n + '-x', function (v) { marker.x = v / 100; commit(); }));
                fields.appendChild(numberField('Y %', Math.round(marker.y * 100), 0, 100, block.key + ':marker-' + n + '-y', function (v) { marker.y = v / 100; commit(); }));
                row.appendChild(fields);
                const remove = smallButton('Remove', 'Remove marker ' + (n + 1), function () {
                    block.markers.splice(n, 1);
                    commit();
                }, block.key + ':marker-' + n + '-remove');
                row.appendChild(remove);
                wrap.appendChild(row);
            });

            if (block.markers.length < LIMITS.markers) {
                wrap.appendChild(smallButton('Add marker at center', 'Add a marker, then set its coordinates', function () {
                    block.markers.push({ x: 0.5, y: 0.5, label: '', note: '' });
                    commit();
                }, block.key + ':marker-add'));
            }
            return wrap;
        }

        function renderStepPhotos(block) {
            if (!Array.isArray(block.media_ids)) {
                block.media_ids = [];
            }
            const wrap = document.createElement('div');
            wrap.className = 'space-y-2';
            const head = document.createElement('p');
            head.className = 'text-xs text-zinc-500';
            head.textContent = 'Photos (' + block.media_ids.length + '/' + LIMITS.stepMedia + ')';
            wrap.appendChild(head);

            const attached = document.createElement('div');
            attached.className = 'flex flex-wrap gap-2';
            block.media_ids.forEach(function (id) {
                const info = imageById[id];
                const chip = document.createElement('span');
                chip.className = 'flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-1.5 text-xs';
                if (info && info.thumb_url) {
                    const thumb = document.createElement('img');
                    thumb.src = info.thumb_url;
                    thumb.alt = '';
                    thumb.loading = 'lazy';
                    thumb.className = 'h-16 w-16 rounded object-cover';
                    chip.appendChild(thumb);
                } else if (info && info.status === 'processing') {
                    const pending = document.createElement('span');
                    pending.className = 'flex h-16 w-16 items-center justify-center rounded bg-zinc-100 px-1 text-center text-zinc-500';
                    pending.textContent = 'Processing…';
                    chip.appendChild(pending);
                }
                const name = document.createElement('span');
                name.className = 'max-w-40 truncate';
                name.textContent = info ? (info.title || 'untitled') : 'Image #' + id + ' (unavailable)';
                chip.appendChild(name);
                chip.appendChild(smallButton('×', 'Remove photo', function () {
                    block.media_ids = block.media_ids.filter(function (kept) { return kept !== id; });
                    commit();
                }, block.key + ':stepphoto-' + id));
                attached.appendChild(chip);
            });
            wrap.appendChild(attached);

            const ready = images.filter(function (img) { return img.thumb_url && block.media_ids.indexOf(img.id) === -1; });
            if (ready.length > 0 && block.media_ids.length < LIMITS.stepMedia) {
                const details = document.createElement('details');
                const summary = document.createElement('summary');
                summary.className = 'cursor-pointer text-xs font-semibold text-zinc-700';
                summary.textContent = 'Attach a library photo';
                details.appendChild(summary);
                const grid = document.createElement('div');
                grid.className = 'mt-2 grid grid-cols-4 gap-2 sm:grid-cols-6';
                ready.forEach(function (img) {
                    const pick = document.createElement('button');
                    pick.type = 'button';
                    pick.className = 'overflow-hidden rounded-lg border border-zinc-200 bg-white hover:border-emerald-500';
                    pick.title = img.title || 'untitled';
                    pick.setAttribute('aria-label', 'Attach ' + (img.title || 'untitled'));
                    const thumb = document.createElement('img');
                    thumb.src = img.thumb_url;
                    thumb.alt = '';
                    thumb.loading = 'lazy';
                    thumb.className = 'aspect-square w-full object-cover';
                    pick.appendChild(thumb);
                    pick.addEventListener('click', function () {
                        if (block.media_ids.length < LIMITS.stepMedia) {
                            block.media_ids.push(img.id);
                            commit();
                        }
                    });
                    grid.appendChild(pick);
                });
                details.appendChild(grid);
                wrap.appendChild(details);
            }
            return wrap;
        }

        // Editing state survives the block-list rebuild below: the
        // focused control (by stable key), its caret/selection and
        // scroll, every other field's scroll, and the page scroll are
        // captured before the rebuild and restored after. Renders
        // therefore never collapse textareas, move focus, or jump.
        function captureState() {
            const state = { focus: null, scrolls: {}, winX: window.scrollX, winY: window.scrollY };
            list.querySelectorAll('[data-fkey]').forEach(function (el) {
                const fkey = el.getAttribute('data-fkey');
                if ((el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') && typeof el.scrollTop === 'number') {
                    state.scrolls[fkey] = el.scrollTop;
                }
            });
            const active = document.activeElement;
            if (active && active.getAttribute && list.contains(active)) {
                const fkey = active.getAttribute('data-fkey');
                if (fkey) {
                    state.focus = {
                        fkey: fkey,
                        start: typeof active.selectionStart === 'number' ? active.selectionStart : null,
                        end: typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
                    };
                }
            }
            return state;
        }

        function restoreState(state) {
            Object.keys(state.scrolls).forEach(function (fkey) {
                const el = list.querySelector('[data-fkey="' + CSS.escape(fkey) + '"]');
                if (el && typeof el.scrollTop === 'number') {
                    el.scrollTop = state.scrolls[fkey];
                }
            });
            if (state.focus) {
                const el = list.querySelector('[data-fkey="' + CSS.escape(state.focus.fkey) + '"]');
                if (el && typeof el.focus === 'function') {
                    el.focus({ preventScroll: true });
                    if (state.focus.start !== null && typeof el.setSelectionRange === 'function') {
                        try {
                            el.setSelectionRange(state.focus.start, state.focus.end);
                        } catch (_) {}
                    }
                    if (typeof state.scrolls[state.focus.fkey] === 'number' && typeof el.scrollTop === 'number') {
                        el.scrollTop = state.scrolls[state.focus.fkey];
                    }
                }
            }
            if (Math.abs(window.scrollX - state.winX) > 2 || Math.abs(window.scrollY - state.winY) > 2) {
                window.scrollTo(state.winX, state.winY);
            }
        }

        function sizeAllTextareas() {
            list.querySelectorAll('textarea.editor-textarea').forEach(function (area) {
                applyRestoredHeight(area, area.getAttribute('data-pos') || '');
                autoSize(area);
            });
        }

        function focusAfterRemove(index) {
            const survivor = list.querySelector('[data-block-index="' + index + '"]')
                || list.querySelector('[data-block-index="' + (index - 1) + '"]');
            const target = survivor
                ? survivor.querySelector('textarea, input, select, button')
                : root.querySelector('[data-add-block="paragraph"]');
            if (target && typeof target.focus === 'function') {
                target.focus();
            }
        }

        function focusBlockField(key, field) {
            const el = list.querySelector('[data-fkey="' + CSS.escape(key + ':' + field) + '"]')
                || list.querySelector('[data-block-key="' + CSS.escape(key) + '"] textarea, [data-block-key="' + CSS.escape(key) + '"] input, [data-block-key="' + CSS.escape(key) + '"] select, [data-block-key="' + CSS.escape(key) + '"] button');
            if (el && typeof el.focus === 'function') {
                el.focus();
            }
        }

        function helpDetails(summary, text) {
            const details = document.createElement('details');
            details.className = 'mt-1 text-xs text-zinc-500';
            const caption = document.createElement('summary');
            caption.className = 'cursor-pointer font-semibold';
            caption.textContent = summary;
            details.appendChild(caption);
            const body = document.createElement('p');
            body.className = 'mt-1';
            body.textContent = text;
            details.appendChild(body);
            return details;
        }

        function renderImagePreview(block, info) {
            const figure = document.createElement('figure');
            figure.className = 'min-w-0';
            if (info && (info.card_url || info.thumb_url)) {
                const img = document.createElement('img');
                img.src = info.card_url || info.thumb_url;
                img.alt = info.alt || '';
                img.loading = 'lazy';
                img.className = 'h-auto w-full rounded-lg border border-zinc-200 bg-white object-contain';
                figure.appendChild(img);
                const caption = document.createElement('figcaption');
                caption.className = 'mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500';
                const name = document.createElement('span');
                name.className = 'min-w-0 flex-1 truncate font-semibold';
                name.textContent = info.title || 'untitled';
                caption.appendChild(name);
                if (info.wide_url) {
                    const larger = document.createElement('a');
                    larger.href = info.wide_url;
                    larger.target = '_blank';
                    larger.rel = 'noopener';
                    larger.className = 'shrink-0 font-semibold text-emerald-700 hover:text-emerald-600';
                    larger.textContent = 'Open larger';
                    caption.appendChild(larger);
                }
                figure.appendChild(caption);
            } else if (info && info.status === 'processing') {
                const pending = document.createElement('p');
                pending.className = 'rounded-lg border border-dashed border-zinc-300 bg-white px-4 py-10 text-center text-sm text-zinc-500';
                pending.textContent = 'Still processing — refresh the page shortly.';
                figure.appendChild(pending);
            } else if (info && info.status === 'failed') {
                const failed = document.createElement('p');
                failed.className = 'rounded-lg border border-red-200 bg-red-50 px-4 py-10 text-center text-sm text-red-700';
                failed.textContent = 'Processing failed — use “Retry processing” in the media library, then pick the photo again.';
                figure.appendChild(failed);
            } else {
                const missing = document.createElement('p');
                missing.className = 'rounded-lg border border-dashed border-zinc-300 bg-white px-4 py-10 text-center text-sm text-zinc-500';
                missing.textContent = info ? 'Preview unavailable for this photo.' : 'Image #' + block.media_id + ' is no longer available — pick another photo below.';
                figure.appendChild(missing);
            }
            return figure;
        }

        function render() {
            const saved = captureState();
            fkeyPositions.clear();
            list.innerHTML = '';
            doc.blocks.forEach(function (block, index) {
                const item = document.createElement('li');
                item.className = 'rounded-xl border border-zinc-200 bg-zinc-50/50 p-3 sm:p-4';
                item.setAttribute('data-block-index', String(index));
                item.setAttribute('data-block-key', block.key);

                const head = document.createElement('div');
                head.className = 'flex items-center justify-between gap-2';
                const title = document.createElement('span');
                title.className = 'text-xs font-semibold text-zinc-500';
                title.textContent = blockLabel(block, index);
                head.appendChild(title);

                const controls = document.createElement('div');
                controls.className = 'flex gap-1';
                const up = smallButton('↑', 'Move up', function () { move(index, -1); }, block.key + ':up');
                up.disabled = index === 0;
                const down = smallButton('↓', 'Move down', function () { move(index, 1); }, block.key + ':down');
                down.disabled = index === doc.blocks.length - 1;
                const del = smallButton('Remove', 'Remove block', function () {
                    doc.blocks.splice(index, 1);
                    commit();
                    focusAfterRemove(index);
                }, block.key + ':remove');
                controls.append(up, down, del);
                head.appendChild(controls);
                item.appendChild(head);

                const body = document.createElement('div');
                body.className = 'mt-2 space-y-2';
                if (block.type === 'paragraph' || block.type === 'quote' || block.type === 'code') {
                    body.appendChild(textarea('Text', block.text || '', { rows: block.type === 'code' ? 5 : 3, max: LIMITS.text, fkey: block.key + ':text', pos: index + ':text' }, function (v) {
                        block.text = v;
                        touch();
                    }));
                } else if (block.type === 'heading') {
                    const levelLabel = document.createElement('label');
                    levelLabel.className = 'block text-xs text-zinc-500';
                    levelLabel.textContent = 'Level';
                    const level = document.createElement('select');
                    level.className = 'mt-1 rounded-lg border-zinc-300 text-sm';
                    level.setAttribute('data-fkey', block.key + ':level');
                    [2, 3].forEach(function (n) {
                        const opt = document.createElement('option');
                        opt.value = String(n);
                        opt.textContent = n === 2 ? 'Section heading' : 'Sub heading';
                        level.appendChild(opt);
                    });
                    level.value = String(block.level || 2);
                    level.addEventListener('change', function () { block.level = Number(level.value); commit(); });
                    levelLabel.appendChild(level);
                    body.appendChild(levelLabel);
                    body.appendChild(textarea('Text', block.text || '', { rows: 2, max: LIMITS.text, fkey: block.key + ':text', pos: index + ':text' }, function (v) { block.text = v; touch(); }));
                } else if (block.type === 'list') {
                    const typeLabel = document.createElement('label');
                    typeLabel.className = 'flex items-center gap-2 text-xs text-zinc-500';
                    const ordered = document.createElement('input');
                    ordered.type = 'checkbox';
                    ordered.checked = Boolean(block.ordered);
                    ordered.setAttribute('data-fkey', block.key + ':ordered');
                    ordered.addEventListener('change', function () { block.ordered = ordered.checked; commit(); });
                    typeLabel.append(ordered, document.createTextNode('Ordered (numbered) list'));
                    body.appendChild(typeLabel);
                    const itemsArea = textarea('One item per line', (block.items || []).join('\n'), { rows: 4, max: LIMITS.text, fkey: block.key + ':items', pos: index + ':items' }, function (v) {
                        block.items = v.split('\n');
                        touch();
                        paintListCount();
                    });
                    body.appendChild(itemsArea);
                    const listCount = document.createElement('p');
                    listCount.className = 'text-xs text-zinc-400';
                    body.appendChild(listCount);
                    const paintListCount = function () {
                        const items = block.items || [];
                        const longest = items.reduce(function (m, item) { return Math.max(m, countChars(item)); }, 0);
                        listCount.textContent = formatCount(items.length) + ' / ' + formatCount(LIMITS.items) + ' items · longest ' + formatCount(longest) + ' / ' + formatCount(LIMITS.text) + ' characters';
                        const over = items.length > LIMITS.items || longest > LIMITS.text;
                        listCount.classList.toggle('editor-counter-over', over);
                    };
                    paintListCount();
                } else if (block.type === 'image') {
                    const info = imageById[block.media_id];
                    const grid = document.createElement('div');
                    grid.className = 'grid gap-3 lg:grid-cols-[minmax(0,460px)_minmax(0,1fr)]';
                    grid.appendChild(renderImagePreview(block, info));
                    const controlsBox = document.createElement('div');
                    controlsBox.className = 'min-w-0 space-y-2';
                    const layoutLabel = document.createElement('label');
                    layoutLabel.className = 'block text-xs text-zinc-500';
                    layoutLabel.textContent = 'Layout';
                    const layout = document.createElement('select');
                    layout.className = 'mt-1 rounded-lg border-zinc-300 text-sm';
                    layout.setAttribute('data-fkey', block.key + ':layout');
                    [['normal', 'Normal content width'], ['wide', 'Wide']].forEach(function ([value, label]) {
                        const opt = document.createElement('option');
                        opt.value = value;
                        opt.textContent = label;
                        layout.appendChild(opt);
                    });
                    layout.value = block.layout || 'normal';
                    layout.addEventListener('change', function () { block.layout = layout.value; commit(); });
                    layoutLabel.appendChild(layout);
                    controlsBox.appendChild(layoutLabel);
                    const swap = smallButton('Pick another photo', 'Replace this block’s photo', function () {
                        pendingImageSwap = block.key;
                        openImagePicker();
                    }, block.key + ':swap');
                    controlsBox.appendChild(swap);
                    if (markersEnabled) {
                        const captionWrap = document.createElement('div');
                        captionWrap.appendChild(textLine('Caption', block.caption || '', LIMITS.caption, block.key + ':caption', function (v) {
                            if (v.trim() === '') {
                                delete block.caption;
                            } else {
                                block.caption = v;
                            }
                            touch();
                        }, 'Optional — falls back to the library caption'));
                        captionWrap.appendChild(helpDetails('When is the fallback used?', 'Leave this empty to use the photo’s library caption. Anything typed here replaces it on this page only.'));
                        controlsBox.appendChild(captionWrap);
                        const altWrap = document.createElement('div');
                        altWrap.appendChild(textLine('Alternative text', block.alt || '', LIMITS.caption, block.key + ':alt', function (v) {
                            if (v.trim() === '') {
                                delete block.alt;
                            } else {
                                block.alt = v;
                            }
                            touch();
                        }, 'Optional — falls back to the library text'));
                        altWrap.appendChild(helpDetails('When is the fallback used?', 'Leave this empty to use the photo’s library alt text. Anything typed here replaces it on this page only.'));
                        controlsBox.appendChild(altWrap);
                    }
                    grid.appendChild(controlsBox);
                    body.appendChild(grid);
                    if (markersEnabled) {
                        body.appendChild(renderMarkerEditor(block, index));
                    }
                } else if (block.type === 'step') {
                    const stepNo = doc.blocks.slice(0, index + 1).filter(function (b) { return b.type === 'step'; }).length;
                    const num = document.createElement('p');
                    num.className = 'text-xs font-semibold text-zinc-500';
                    num.textContent = 'Step ' + stepNo + ' (numbered automatically)';
                    body.appendChild(num);
                    body.appendChild(textLine('Title (optional)', block.title || '', LIMITS.title, block.key + ':title', function (v) {
                        if (v.trim() === '') {
                            delete block.title;
                        } else {
                            block.title = v;
                        }
                        touch();
                    }));
                    body.appendChild(textarea('Instructions', block.text || '', { rows: 3, max: LIMITS.text, fkey: block.key + ':text', pos: index + ':text' }, function (v) {
                        block.text = v;
                        touch();
                    }));
                    body.appendChild(renderStepPhotos(block));
                } else if (block.type === 'doc_link') {
                    if (docsPages.length === 0) {
                        const note = document.createElement('p');
                        note.className = 'text-sm text-zinc-500';
                        note.textContent = 'No other documentation pages exist yet.';
                        body.appendChild(note);
                    } else {
                        const pickLabel = document.createElement('label');
                        pickLabel.className = 'block text-xs text-zinc-500';
                        pickLabel.textContent = 'Linked page';
                        const pick = document.createElement('select');
                        pick.setAttribute('data-fkey', block.key + ':doc');
                        pick.className = 'mt-1 block w-full rounded-lg border-zinc-300 text-sm';
                        docsPages.forEach(function (p) {
                            const opt = document.createElement('option');
                            opt.value = String(p.id);
                            opt.textContent = '[' + p.category + '] ' + p.title;
                            pick.appendChild(opt);
                        });
                        pick.value = String(block.doc_id || docsPages[0].id);
                        block.doc_id = Number(pick.value);
                        pick.addEventListener('change', function () { block.doc_id = Number(pick.value); commit(); });
                        pickLabel.appendChild(pick);
                        body.appendChild(pickLabel);
                    }
                    const labelWrap = document.createElement('div');
                    labelWrap.appendChild(textLine('Label', block.label || '', LIMITS.title, block.key + ':label', function (v) {
                        if (v.trim() === '') {
                            delete block.label;
                        } else {
                            block.label = v;
                        }
                        touch();
                    }, 'Optional — falls back to the page title'));
                    labelWrap.appendChild(helpDetails('When is the fallback used?', 'Leave this empty to use the linked page’s title. Anything typed here replaces it on this link only.'));
                    body.appendChild(labelWrap);
                }
                item.appendChild(body);
                list.appendChild(item);
            });

            if (undoButton) {
                undoButton.disabled = history.length < 2;
            }
            if (redoButton) {
                redoButton.disabled = future.length === 0;
            }
            paintBlockCount();
            sizeAllTextareas();
            restoreState(saved);
        }

        function paintBlockCount() {
            let counter = root.querySelector('[data-block-count]');
            if (!counter) {
                counter = document.createElement('p');
                counter.setAttribute('data-block-count', '');
                counter.className = 'mt-2 text-xs text-zinc-400';
                if (editorPane) {
                    editorPane.appendChild(counter);
                }
            }
            counter.textContent = formatCount(doc.blocks.length) + ' / ' + formatCount(LIMITS.blocks) + ' blocks';
            counter.classList.toggle('editor-counter-over', doc.blocks.length > LIMITS.blocks);
        }

        // Text input updates state without pushing history on every
        // keystroke; history commits on blur, structure changes, and save.
        let touchTimer = null;
        function touch() {
            setDirty(true);
            sync(false);
            if (touchTimer) {
                clearTimeout(touchTimer);
            }
            touchTimer = setTimeout(function () {
                if (snapshot() !== history[history.length - 1]) {
                    commit();
                }
            }, 1200);
        }

        function move(index, delta) {
            const target = index + delta;
            if (target < 0 || target >= doc.blocks.length) {
                return;
            }
            const [moved] = doc.blocks.splice(index, 1);
            doc.blocks.splice(target, 0, moved);
            commit();
        }

        function sync(renderPreview) {
            if (jsonField) {
                jsonField.value = snapshot();
            }
            if (renderPreview !== false) {
                render();
            }
            renderPreviewPane();
        }

        function renderPreviewPane() {
            if (!previewPane) {
                return;
            }
            let html = '';
            doc.blocks.forEach(function (block) {
                if (block.type === 'paragraph') {
                    html += '<p>' + inlinePreview(block.text || '') + '</p>';
                } else if (block.type === 'heading') {
                    html += block.level === 3 ? '<h4>' + inlinePreview(block.text || '') + '</h4>' : '<h3>' + inlinePreview(block.text || '') + '</h3>';
                } else if (block.type === 'quote') {
                    html += '<blockquote>' + inlinePreview(block.text || '') + '</blockquote>';
                } else if (block.type === 'code') {
                    html += '<pre><code>' + esc(block.text || '') + '</code></pre>';
                } else if (block.type === 'list') {
                    const tag = block.ordered ? 'ol' : 'ul';
                    html += '<' + tag + '>' + (block.items || []).map(function (i) { return '<li>' + inlinePreview(i) + '</li>'; }).join('') + '</' + tag + '>';
                } else if (block.type === 'image') {
                    const info = imageById[block.media_id];
                    const caption = (block.caption || '').trim() !== '' ? block.caption : (info && info.caption ? info.caption : '');
                    const alt = (block.alt || '').trim() !== '' ? block.alt : (info && info.alt ? info.alt : '');
                    html += info && (info.card_url || info.thumb_url)
                        ? '<figure><img src="' + esc(info.card_url || info.thumb_url) + '" alt="' + esc(alt) + '" style="max-width:100%;border-radius:12px" />' + (caption ? '<figcaption>' + esc(caption) + '</figcaption>' : '') + '</figure>'
                        : '<p><em>[Image unavailable]</em></p>';
                    (block.markers || []).forEach(function (marker, n) {
                        const label = ((marker.label || '') + ' ' + (marker.note || '')).trim();
                        html += '<p><em>Marker ' + (n + 1) + (label ? ': ' + esc(label) : '') + '</em></p>';
                    });
                } else if (block.type === 'step') {
                    const stepNo = doc.blocks.slice(0, doc.blocks.indexOf(block) + 1).filter(function (b) { return b.type === 'step'; }).length;
                    html += '<h4>Step ' + stepNo + ((block.title || '').trim() !== '' ? ': ' + inlinePreview(block.title) : '') + '</h4>';
                    html += '<p>' + inlinePreview(block.text || '') + '</p>';
                    (block.media_ids || []).forEach(function (id) {
                        const info = imageById[id];
                        html += info && (info.card_url || info.thumb_url)
                            ? '<figure><img src="' + esc(info.card_url || info.thumb_url) + '" alt="' + esc(info.alt || '') + '" style="max-width:100%;border-radius:12px" /></figure>'
                            : '<p><em>[Image unavailable]</em></p>';
                    });
                } else if (block.type === 'doc_link') {
                    const target = docsPages.filter(function (p) { return p.id === block.doc_id; })[0];
                    const label = (block.label || '').trim() !== '' ? block.label : (target ? target.title : '');
                    html += '<p><em>Page link: ' + esc(label || '(choose a page)') + '</em></p>';
                }
            });
            previewPane.innerHTML = html || '<p><em>Nothing to preview yet.</em></p>';
        }

        root.querySelectorAll('[data-add-block]').forEach(function (button) {
            button.addEventListener('click', function () {
                const type = button.getAttribute('data-add-block');
                if (type === 'image') {
                    pendingImageSwap = null;
                    openImagePicker();
                    return;
                }
                const block = newBlock(type);
                doc.blocks.push(block);
                commit();
                const firstField = block.type === 'list' ? 'items' : (block.type === 'doc_link' ? 'doc' : 'text');
                focusBlockField(block.key, firstField);
            });
        });

        function openImagePicker() {
            const dialog = root.querySelector('[data-image-picker]');
            if (!dialog) {
                return;
            }
            dialog.hidden = false;
            const first = dialog.querySelector('button[data-pick]');
            if (first) {
                first.focus();
            }
        }

        // Which image block (by stable key) the open picker replaces,
        // or null when the picker appends a new block.
        let pendingImageSwap = null;

        // Delegated: entries appended after a fetch upload work too.
        const pickerDialog = root.querySelector('[data-image-picker]');
        if (pickerDialog) {
            pickerDialog.addEventListener('click', function (event) {
                const button = event.target.closest('button[data-pick]');
                if (!button || button.disabled) {
                    return;
                }
                const mediaId = Number(button.getAttribute('data-pick'));
                const dialog = root.querySelector('[data-image-picker]');
                if (dialog) {
                    dialog.hidden = true;
                }
                if (pendingImageSwap) {
                    const target = doc.blocks.filter(function (b) { return b.key === pendingImageSwap; })[0];
                    pendingImageSwap = null;
                    if (target) {
                        target.media_id = mediaId;
                        commit();
                        focusBlockField(target.key, 'layout');
                        return;
                    }
                }
                keyCounter += 1;
                const block = { type: 'image', media_id: mediaId, layout: 'normal', key: 'b' + keyCounter.toString(36) + Date.now().toString(36) };
                doc.blocks.push(block);
                commit();
                focusBlockField(block.key, 'layout');
            });
        }

        const pickerClose = root.querySelector('[data-picker-close]');
        if (pickerClose) {
            pickerClose.addEventListener('click', function () {
                pendingImageSwap = null;
                const dialog = root.querySelector('[data-image-picker]');
                if (dialog) {
                    dialog.hidden = true;
                }
                const addImage = root.querySelector('[data-add-block="image"]');
                if (addImage) {
                    addImage.focus();
                }
            });
        }

        // Photos uploaded mid-edit (fetch path) join the library maps
        // and the picker without a page reload; step-photo attach
        // lists pick them up on the next render.
        document.addEventListener('doc-images-added', function (event) {
            const added = (event.detail && event.detail.images) || [];
            if (added.length === 0) {
                return;
            }
            const dialog = root.querySelector('[data-image-picker]');
            let grid = root.querySelector('[data-image-picker] ul');
            const emptyNote = root.querySelector('[data-image-picker] [data-picker-empty]');
            if (emptyNote) {
                emptyNote.remove();
            }
            if (!grid && dialog) {
                grid = document.createElement('ul');
                grid.className = 'mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6';
                const closer = dialog.querySelector('[data-picker-close]');
                if (closer) {
                    dialog.insertBefore(grid, closer);
                } else {
                    dialog.appendChild(grid);
                }
            }
            added.forEach(function (img) {
                if (!img || typeof img.id === 'undefined' || imageById[img.id]) {
                    return;
                }
                images.push(img);
                imageById[img.id] = img;
                if (!grid) {
                    return;
                }
                const item = document.createElement('li');
                const ready = Boolean(img.thumb_url);
                const pick = document.createElement('button');
                pick.type = 'button';
                pick.setAttribute('data-pick', String(img.id));
                pick.className = 'block w-full overflow-hidden rounded-lg border border-zinc-200 bg-white hover:border-emerald-500';
                if (!ready) {
                    pick.disabled = true;
                }
                if (ready) {
                    const thumb = document.createElement('img');
                    thumb.src = img.thumb_url;
                    thumb.alt = '';
                    thumb.loading = 'lazy';
                    thumb.className = 'aspect-square w-full object-cover';
                    pick.appendChild(thumb);
                } else {
                    const state = document.createElement('span');
                    state.className = 'flex aspect-square flex-col items-center justify-center gap-1 px-1 text-center text-xs '
                        + (img.status === 'failed' ? 'bg-red-50 font-semibold text-red-700' : 'text-zinc-500');
                    state.textContent = img.status === 'failed' ? 'Failed' : 'Processing…';
                    pick.appendChild(state);
                }
                const name = document.createElement('span');
                name.className = 'block truncate px-1 py-1 text-xs';
                name.textContent = img.title || 'untitled';
                pick.appendChild(name);
                item.appendChild(pick);
                grid.appendChild(item);
            });
            render();
        });

        if (undoButton) {
            undoButton.addEventListener('click', function () {
                if (history.length < 2) {
                    return;
                }
                future.push(history.pop());
                doc = JSON.parse(history[history.length - 1]);
                setDirty(true);
                sync();
            });
        }
        if (redoButton) {
            redoButton.addEventListener('click', function () {
                if (future.length === 0) {
                    return;
                }
                const next = future.pop();
                history.push(next);
                doc = JSON.parse(next);
                setDirty(true);
                sync();
            });
        }

        root.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && !event.shiftKey) {
                event.preventDefault();
                if (undoButton) {
                    undoButton.click();
                }
            }
            if ((event.ctrlKey || event.metaKey) && (event.key.toLowerCase() === 'y' || (event.key.toLowerCase() === 'z' && event.shiftKey))) {
                event.preventDefault();
                if (redoButton) {
                    redoButton.click();
                }
            }
        });

        if (previewToggle && previewPane && editorPane) {
            previewToggle.addEventListener('click', function () {
                const showing = previewPane.hidden;
                previewPane.hidden = !showing;
                editorPane.hidden = showing;
                previewToggle.textContent = showing ? 'Back to editing' : 'Preview';
                if (showing) {
                    renderPreviewPane();
                }
            });
        }

        const form = root.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                saving = true;
                setDirty(true);
                if (jsonField) {
                    jsonField.value = snapshot();
                }
                clearBackup();
                persistHeights();
            });
        }

        window.addEventListener('beforeunload', function () {
            persistHeights();
            if (dirty && !saving) {
                backupDraft();
            }
        });

        window.addEventListener('beforeunload', function (event) {
            if (dirty && !saving && !uploadingDocDraft) {
                event.preventDefault();
            }
        });

        function withoutKeys(value) {
            return JSON.stringify(value, function (k, v) { return k === 'key' ? undefined : v; });
        }

        // A backup that differs from the loaded page offers an explicit
        // restore: the server content is never replaced on its own.
        function maybeOfferRestore() {
            const backup = loadBackup();
            if (!backup) {
                return;
            }
            let backupDoc = null;
            try {
                backupDoc = JSON.parse(backup.json);
            } catch (_) {
                backupDoc = null;
            }
            if (!backupDoc || !Array.isArray(backupDoc.blocks)) {
                clearBackup();
                return;
            }
            if (withoutKeys(backupDoc) === withoutKeys(doc)) {
                clearBackup();
                return;
            }
            const bar = document.createElement('div');
            bar.className = 'mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900';
            bar.setAttribute('role', 'status');
            const when = new Date(backup.at);
            const text = document.createElement('span');
            text.className = 'min-w-0 flex-1';
            text.textContent = 'An unsaved backup from ' + (Number.isNaN(when.getTime()) ? 'an earlier session' : when.toLocaleString()) + ' differs from the loaded page.';
            bar.appendChild(text);
            const restore = document.createElement('button');
            restore.type = 'button';
            restore.className = 'shrink-0 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100';
            restore.textContent = 'Restore backup';
            restore.addEventListener('click', function () {
                doc = backupDoc;
                ensureKeys();
                history.push(snapshot());
                future = [];
                setDirty(true);
                sync();
                bar.remove();
            });
            bar.appendChild(restore);
            const discard = document.createElement('button');
            discard.type = 'button';
            discard.className = 'shrink-0 rounded-lg border border-transparent px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100';
            discard.textContent = 'Discard';
            discard.addEventListener('click', function () {
                clearBackup();
                bar.remove();
            });
            bar.appendChild(discard);
            if (editorPane && editorPane.parentNode) {
                editorPane.parentNode.insertBefore(bar, editorPane);
            } else {
                root.prepend(bar);
            }
        }

        // Inline-syntax helpers insert markup at the cursor of the
        // focused block textarea.
        root.querySelectorAll('[data-insert]').forEach(function (button) {
            button.addEventListener('click', function () {
                const active = document.activeElement;
                if (!active || active.tagName !== 'TEXTAREA' || !list.contains(active)) {
                    return;
                }
                const snippet = button.getAttribute('data-insert') || '';
                const start = active.selectionStart || 0;
                const end = active.selectionEnd || 0;
                active.setRangeText(snippet, start, end, 'end');
                active.dispatchEvent(new Event('input', { bubbles: true }));
                active.focus();
            });
        });

        setDirty(false);
        maybeOfferRestore();
        sync();
    }

    function initGalleryOrder(root) {
        // Each item carries a hidden order[] input, so plain submission
        // preserves DOM order with or without JavaScript; the buttons
        // below only rearrange the DOM.
        const list = root.querySelector('[data-order-list]');
        if (!list) {
            return;
        }
        list.querySelectorAll('[data-move]').forEach(function (button) {
            button.addEventListener('click', function () {
                const item = button.closest('[data-order-id]');
                if (!item) {
                    return;
                }
                const sibling = button.getAttribute('data-move') === 'up' ? item.previousElementSibling : item.nextElementSibling;
                if (sibling) {
                    if (button.getAttribute('data-move') === 'up') {
                        list.insertBefore(item, sibling);
                    } else {
                        list.insertBefore(sibling, item);
                    }
                }
            });
        });
    }

    function initFocalPoint(root) {
        const picker = root.querySelector('[data-focal-picker]');
        const xField = root.querySelector('input[name="focal_x"]');
        const yField = root.querySelector('input[name="focal_y"]');
        const marker = root.querySelector('[data-focal-marker]');
        if (!picker || !xField || !yField) {
            return;
        }
        function place(clientX, clientY) {
            const rect = picker.getBoundingClientRect();
            const x = Math.round(Math.min(100, Math.max(0, ((clientX - rect.left) / rect.width) * 100)));
            const y = Math.round(Math.min(100, Math.max(0, ((clientY - rect.top) / rect.height) * 100)));
            xField.value = String(x);
            yField.value = String(y);
            if (marker) {
                marker.style.left = x + '%';
                marker.style.top = y + '%';
            }
        }
        picker.addEventListener('click', function (event) {
            place(event.clientX, event.clientY);
        });
        picker.addEventListener('keydown', function (event) {
            const step = event.shiftKey ? 10 : 2;
            let x = Number(xField.value || 50);
            let y = Number(yField.value || 50);
            if (event.key === 'ArrowLeft') {
                x -= step;
            } else if (event.key === 'ArrowRight') {
                x += step;
            } else if (event.key === 'ArrowUp') {
                y -= step;
            } else if (event.key === 'ArrowDown') {
                y += step;
            } else {
                return;
            }
            event.preventDefault();
            xField.value = String(Math.min(100, Math.max(0, x)));
            yField.value = String(Math.min(100, Math.max(0, y)));
            if (marker) {
                marker.style.left = xField.value + '%';
                marker.style.top = yField.value + '%';
            }
        });
    }

    function initLightbox(scope) {
        scope.querySelectorAll('[data-gallery]').forEach(function (gallery) {
            const box = gallery.parentElement.querySelector('[data-lightbox]');
            if (!box) {
                return;
            }
            const img = box.querySelector('[data-lightbox-image]');
            const caption = box.querySelector('[data-lightbox-caption]');
            const openers = Array.from(gallery.querySelectorAll('[data-gallery-open]'));
            let index = 0;
            let lastFocus = null;

            function show(i) {
                index = (i + openers.length) % openers.length;
                const opener = openers[index];
                img.src = opener.getAttribute('data-full') || '';
                img.alt = opener.getAttribute('data-alt') || '';
                if (caption) {
                    caption.textContent = opener.getAttribute('data-caption') || opener.getAttribute('data-alt') || '';
                }
            }

            function open(i, opener) {
                lastFocus = opener || document.activeElement;
                box.hidden = false;
                document.body.style.overflow = 'hidden';
                show(i);
                const close = box.querySelector('[data-lightbox-close]');
                if (close) {
                    close.focus();
                }
            }

            function close() {
                box.hidden = true;
                document.body.style.overflow = '';
                img.src = '';
                if (lastFocus && document.contains(lastFocus)) {
                    lastFocus.focus();
                }
            }

            openers.forEach(function (opener, i) {
                opener.addEventListener('click', function () { open(i, opener); });
            });
            const closeButton = box.querySelector('[data-lightbox-close]');
            if (closeButton) {
                closeButton.addEventListener('click', close);
            }
            const prev = box.querySelector('[data-lightbox-prev]');
            if (prev) {
                prev.addEventListener('click', function () { show(index - 1); });
            }
            const next = box.querySelector('[data-lightbox-next]');
            if (next) {
                next.addEventListener('click', function () { show(index + 1); });
            }
            box.addEventListener('click', function (event) {
                if (event.target === box) {
                    close();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (box.hidden) {
                    return;
                }
                if (event.key === 'Escape') {
                    close();
                } else if (event.key === 'ArrowLeft') {
                    show(index - 1);
                } else if (event.key === 'ArrowRight') {
                    show(index + 1);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-story-editor]').forEach(initEditor);
        document.querySelectorAll('[data-gallery-order]').forEach(initGalleryOrder);
        document.querySelectorAll('[data-focal]').forEach(initFocalPoint);
        initLightbox(document);
        initUploadFeedback(document);
        // Failed saves land back on the form with a summary: put
        // focus (and scroll) there so the errors are found at once.
        const summary = document.querySelector('[data-error-summary]');
        if (summary && typeof summary.focus === 'function') {
            summary.focus();
        }
    });
})();
