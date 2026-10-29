// Project story block editor: paragraphs, headings, lists, quotes, code,
// and inline images from the project's own media library. Vanilla JS,
// no editor framework: the document is a small validated JSON schema
// (see StoryDocument) rendered into discrete block controls.
//
// Guarantees: undo/redo history, dirty/saving/saved states, unsaved
// changes warning, draft preservation across validation failures (the
// JSON round-trips through old input), and a client-side preview that
// mirrors the server renderer (escape-first; the server re-validates).
(function () {
    'use strict';

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
        const jsonField = root.querySelector('input[name="story_document"]');
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
                    html += info && info.thumb_url
                        ? '<figure><img src="' + esc(info.thumb_url) + '" alt="' + esc(info.alt || '') + '" style="max-width:100%;border-radius:12px" />' + (info.caption ? '<figcaption>' + esc(info.caption) + '</figcaption>' : '') + '</figure>'
                        : '<p><em>[Image unavailable]</em></p>';
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
            if (dirty && !saving) {
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

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-story-editor]').forEach(initEditor);
        document.querySelectorAll('[data-gallery-order]').forEach(initGalleryOrder);
        document.querySelectorAll('[data-focal]').forEach(initFocalPoint);
        initLightbox(document);
    });
})();
