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

(function () {
    'use strict';

    // True while a docs photo upload (which carries the editor draft
    // along) is submitting, so the unsaved-changes warning stays quiet.
    let uploadingDocDraft = false;

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

        function snapshot() {
            return JSON.stringify(doc);
        }

        function commit() {
            history.push(snapshot());
            if (history.length > 50) {
                history.shift();
            }
            future = [];
            setDirty(true);
            sync();
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

        function textarea(label, value, rows, onInput) {
            const wrap = document.createElement('label');
            wrap.className = 'block text-xs text-zinc-500';
            wrap.textContent = label;
            const area = document.createElement('textarea');
            area.rows = rows || 3;
            area.className = 'mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500';
            area.value = value;
            area.addEventListener('input', function () { onInput(area.value); });
            wrap.appendChild(area);
            return wrap;
        }

        function smallButton(label, title, onClick) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.title = title;
            button.className = 'rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50';
            button.addEventListener('click', onClick);
            return button;
        }

        function textLine(label, value, maxLength, onInput, placeholder) {
            const wrap = document.createElement('label');
            wrap.className = 'block text-xs text-zinc-500';
            wrap.textContent = label;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500';
            input.value = value || '';
            if (maxLength) {
                input.maxLength = maxLength;
            }
            if (placeholder) {
                input.placeholder = placeholder;
            }
            input.addEventListener('input', function () { onInput(input.value); });
            wrap.appendChild(input);
            return wrap;
        }

        function numberField(label, value, min, max, onInput) {
            const wrap = document.createElement('label');
            wrap.className = 'block text-xs text-zinc-500';
            wrap.textContent = label;
            const input = document.createElement('input');
            input.type = 'number';
            input.min = String(min);
            input.max = String(max);
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

        function renderMarkerEditor(block) {
            if (!Array.isArray(block.markers)) {
                block.markers = [];
            }
            const wrap = document.createElement('div');
            wrap.className = 'space-y-2 rounded-lg border border-zinc-200 p-2';
            const head = document.createElement('p');
            head.className = 'text-xs font-semibold text-zinc-500';
            head.textContent = 'Annotation markers (' + block.markers.length + '/20) — click the photo to place, or add below and set coordinates';
            wrap.appendChild(head);

            const info = imageById[block.media_id];
            if (info && info.thumb_url) {
                const placer = document.createElement('button');
                placer.type = 'button';
                placer.className = 'relative block overflow-hidden rounded-lg border border-zinc-200';
                placer.title = 'Click to place a marker';
                placer.setAttribute('aria-label', 'Place a marker on the photo');
                const img = document.createElement('img');
                img.src = info.thumb_url;
                img.alt = '';
                img.className = 'block max-h-48 w-auto';
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
                    if (block.markers.length >= 20) {
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
                row.className = 'grid grid-cols-[auto_1fr_1fr] items-end gap-2 rounded-lg bg-white p-2';
                const badge = document.createElement('span');
                badge.className = 'doc-marker doc-marker-static';
                badge.textContent = String(n + 1);
                badge.setAttribute('aria-hidden', 'true');
                row.appendChild(badge);
                const fields = document.createElement('div');
                fields.className = 'col-span-2 grid gap-2 sm:grid-cols-2';
                fields.appendChild(textLine('Label', marker.label || '', 80, function (v) { marker.label = v; touch(); }));
                fields.appendChild(textLine('Note', marker.note || '', 500, function (v) { marker.note = v; touch(); }));
                fields.appendChild(numberField('X %', Math.round(marker.x * 100), 0, 100, function (v) { marker.x = v / 100; commit(); }));
                fields.appendChild(numberField('Y %', Math.round(marker.y * 100), 0, 100, function (v) { marker.y = v / 100; commit(); }));
                row.appendChild(fields);
                const remove = smallButton('Remove', 'Remove marker ' + (n + 1), function () {
                    block.markers.splice(n, 1);
                    commit();
                });
                row.appendChild(remove);
                wrap.appendChild(row);
            });

            if (block.markers.length < 20) {
                wrap.appendChild(smallButton('Add marker at center', 'Add a marker, then set its coordinates', function () {
                    block.markers.push({ x: 0.5, y: 0.5, label: '', note: '' });
                    commit();
                }));
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
            head.textContent = 'Photos (' + block.media_ids.length + '/4)';
            wrap.appendChild(head);

            const attached = document.createElement('div');
            attached.className = 'flex flex-wrap gap-2';
            block.media_ids.forEach(function (id) {
                const info = imageById[id];
                const chip = document.createElement('span');
                chip.className = 'flex items-center gap-1 rounded-lg border border-zinc-200 bg-white p-1 text-xs';
                if (info && info.thumb_url) {
                    const thumb = document.createElement('img');
                    thumb.src = info.thumb_url;
                    thumb.alt = '';
                    thumb.className = 'h-10 w-10 rounded object-cover';
                    chip.appendChild(thumb);
                }
                const name = document.createElement('span');
                name.textContent = info ? (info.title || 'untitled') : 'Image #' + id + ' (unavailable)';
                chip.appendChild(name);
                chip.appendChild(smallButton('×', 'Remove photo', function () {
                    block.media_ids = block.media_ids.filter(function (kept) { return kept !== id; });
                    commit();
                }));
                attached.appendChild(chip);
            });
            wrap.appendChild(attached);

            const ready = images.filter(function (img) { return img.thumb_url && block.media_ids.indexOf(img.id) === -1; });
            if (ready.length > 0 && block.media_ids.length < 4) {
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
                        if (block.media_ids.length < 4) {
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

        function render() {
            list.innerHTML = '';
            doc.blocks.forEach(function (block, index) {
                const item = document.createElement('li');
                item.className = 'rounded-xl border border-zinc-200 bg-zinc-50/50 p-3';
                item.setAttribute('data-block-index', String(index));

                const head = document.createElement('div');
                head.className = 'flex items-center justify-between gap-2';
                const title = document.createElement('span');
                title.className = 'text-xs font-semibold text-zinc-500';
                title.textContent = blockLabel(block, index);
                head.appendChild(title);

                const controls = document.createElement('div');
                controls.className = 'flex gap-1';
                const up = smallButton('↑', 'Move up', function () { move(index, -1); });
                up.disabled = index === 0;
                const down = smallButton('↓', 'Move down', function () { move(index, 1); });
                down.disabled = index === doc.blocks.length - 1;
                const del = smallButton('Remove', 'Remove block', function () {
                    doc.blocks.splice(index, 1);
                    commit();
                });
                controls.append(up, down, del);
                head.appendChild(controls);
                item.appendChild(head);

                const body = document.createElement('div');
                body.className = 'mt-2 space-y-2';
                if (block.type === 'paragraph' || block.type === 'quote' || block.type === 'code') {
                    body.appendChild(textarea('Text', block.text || '', block.type === 'code' ? 5 : 3, function (v) {
                        block.text = v;
                        touch();
                    }));
                } else if (block.type === 'heading') {
                    const levelLabel = document.createElement('label');
                    levelLabel.className = 'block text-xs text-zinc-500';
                    levelLabel.textContent = 'Level';
                    const level = document.createElement('select');
                    level.className = 'mt-1 rounded-lg border-zinc-300 text-sm';
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
                    body.appendChild(textarea('Text', block.text || '', 2, function (v) { block.text = v; touch(); }));
                } else if (block.type === 'list') {
                    const typeLabel = document.createElement('label');
                    typeLabel.className = 'flex items-center gap-2 text-xs text-zinc-500';
                    const ordered = document.createElement('input');
                    ordered.type = 'checkbox';
                    ordered.checked = Boolean(block.ordered);
                    ordered.addEventListener('change', function () { block.ordered = ordered.checked; commit(); });
                    typeLabel.append(ordered, document.createTextNode('Ordered (numbered) list'));
                    body.appendChild(typeLabel);
                    body.appendChild(textarea('One item per line', (block.items || []).join('\n'), 4, function (v) {
                        block.items = v.split('\n');
                        touch();
                    }));
                } else if (block.type === 'image') {
                    const info = imageById[block.media_id];
                    const note = document.createElement('p');
                    note.className = 'text-sm';
                    note.textContent = info ? ('Image: ' + (info.title || 'untitled')) : 'Image #' + block.media_id + ' (no longer available — pick another)';
                    body.appendChild(note);
                    if (info && info.thumb_url) {
                        const thumb = document.createElement('img');
                        thumb.src = info.thumb_url;
                        thumb.alt = '';
                        thumb.className = 'h-20 w-20 rounded-lg border border-zinc-200 object-cover';
                        body.appendChild(thumb);
                    }
                    const layoutLabel = document.createElement('label');
                    layoutLabel.className = 'block text-xs text-zinc-500';
                    layoutLabel.textContent = 'Layout';
                    const layout = document.createElement('select');
                    layout.className = 'mt-1 rounded-lg border-zinc-300 text-sm';
                    [['normal', 'Normal content width'], ['wide', 'Wide']].forEach(function ([value, label]) {
                        const opt = document.createElement('option');
                        opt.value = value;
                        opt.textContent = label;
                        layout.appendChild(opt);
                    });
                    layout.value = block.layout || 'normal';
                    layout.addEventListener('change', function () { block.layout = layout.value; commit(); });
                    layoutLabel.appendChild(layout);
                    body.appendChild(layoutLabel);
                    if (markersEnabled) {
                        body.appendChild(textLine('Caption override (optional, falls back to library caption)', block.caption || '', 500, function (v) {
                            if (v.trim() === '') {
                                delete block.caption;
                            } else {
                                block.caption = v;
                            }
                            touch();
                        }));
                        body.appendChild(textLine('Alt text override (optional, falls back to library text)', block.alt || '', 500, function (v) {
                            if (v.trim() === '') {
                                delete block.alt;
                            } else {
                                block.alt = v;
                            }
                            touch();
                        }));
                        body.appendChild(renderMarkerEditor(block));
                    }
                } else if (block.type === 'step') {
                    const stepNo = doc.blocks.slice(0, index + 1).filter(function (b) { return b.type === 'step'; }).length;
                    const num = document.createElement('p');
                    num.className = 'text-xs font-semibold text-zinc-500';
                    num.textContent = 'Step ' + stepNo + ' (numbered automatically)';
                    body.appendChild(num);
                    body.appendChild(textLine('Title (optional)', block.title || '', 200, function (v) {
                        if (v.trim() === '') {
                            delete block.title;
                        } else {
                            block.title = v;
                        }
                        touch();
                    }));
                    body.appendChild(textarea('Instructions', block.text || '', 3, function (v) {
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
                    body.appendChild(textLine('Label (optional, falls back to page title)', block.label || '', 200, function (v) {
                        if (v.trim() === '') {
                            delete block.label;
                        } else {
                            block.label = v;
                        }
                        touch();
                    }));
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
                    html += info && info.thumb_url
                        ? '<figure><img src="' + esc(info.thumb_url) + '" alt="' + esc(alt) + '" style="max-width:100%;border-radius:12px" />' + (caption ? '<figcaption>' + esc(caption) + '</figcaption>' : '') + '</figure>'
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
                        html += info && info.thumb_url
                            ? '<figure><img src="' + esc(info.thumb_url) + '" alt="' + esc(info.alt || '') + '" style="max-width:100%;border-radius:12px" /></figure>'
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
                    openImagePicker();
                    return;
                }
                const block = { type: type, text: '' };
                if (type === 'heading') {
                    block.level = 2;
                }
                if (type === 'list') {
                    block.ordered = false;
                    block.items = [''];
                    delete block.text;
                }
                if (type === 'code') {
                    block.text = '';
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
                doc.blocks.push(block);
                commit();
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

        root.querySelectorAll('button[data-pick]').forEach(function (button) {
            button.addEventListener('click', function () {
                doc.blocks.push({ type: 'image', media_id: Number(button.getAttribute('data-pick')), layout: 'normal' });
                const dialog = root.querySelector('[data-image-picker]');
                if (dialog) {
                    dialog.hidden = true;
                }
                commit();
            });
        });

        const pickerClose = root.querySelector('[data-picker-close]');
        if (pickerClose) {
            pickerClose.addEventListener('click', function () {
                const dialog = root.querySelector('[data-image-picker]');
                if (dialog) {
                    dialog.hidden = true;
                }
            });
        }

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
            });
        }

        window.addEventListener('beforeunload', function (event) {
            if (dirty && !saving && !uploadingDocDraft) {
                event.preventDefault();
            }
        });

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

    function initDocUpload(scope) {
        scope.querySelectorAll('[data-doc-upload]').forEach(function (form) {
            form.addEventListener('submit', function () {
                const main = document.querySelector('form[action*="/docs"] input[name="doc_document"]');
                form.querySelectorAll('[data-draft-field]').forEach(function (hidden) {
                    const name = hidden.getAttribute('data-draft-field');
                    if (name === 'doc_document') {
                        hidden.value = main ? main.value : '';
                        return;
                    }
                    const field = document.querySelector('form[action*="/docs"]:not([data-doc-upload]) [name="' + name + '"]');
                    hidden.value = field ? field.value : '';
                });
                uploadingDocDraft = true;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-story-editor]').forEach(initEditor);
        document.querySelectorAll('[data-gallery-order]').forEach(initGalleryOrder);
        document.querySelectorAll('[data-focal]').forEach(initFocalPoint);
        initLightbox(document);
        initDocUpload(document);
    });
})();
