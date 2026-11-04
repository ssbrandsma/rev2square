// Composer draft preservation: text typed into draft-keyed forms
// survives validation failures, expired sessions, reconnects, and
// crashes (localStorage, namespaced by account via drafts.js).
//
// Every named textarea and text/url input gets its own per-field key,
// so multi-field forms restore each field independently. Fields are
// only ever filled when empty: server content (including failed-save
// old input) always wins, and nothing is restored over user text.
// Submit clears the form's keys. Chat has its own single-field
// manager (chat-room.js) and is excluded here.
import { currentUserId } from '../echo.js';
import { clearDraft, loadDraft, saveDraft } from '../drafts.js';

(function () {
    'use strict';

    // Text-composing controls only: single-line text/url inputs and
    // textareas. Checkboxes, radios, selects, dates, files, and hidden
    // inputs are quick to redo and stay server-driven.
    const FIELD_SELECTOR = 'textarea[name], input[type="text"][name], input[type="url"][name], input[type="search"][name]';

    function formFields(form) {
        return Array.from(form.querySelectorAll(FIELD_SELECTOR)).filter(function (field) {
            return field.name !== '' && !field.disabled && field.type !== 'hidden';
        });
    }

    function restore(form) {
        const key = form.getAttribute('data-draft-key');
        if (!key) {
            return;
        }

        const userId = currentUserId();
        formFields(form).forEach(function (field) {
            if (field.value !== '') {
                return;
            }
            const draft = loadDraft(window.localStorage, userId, key + ':' + field.name);
            if (draft) {
                field.value = draft;
            }
        });

        // Legacy single-value drafts (written before per-field keys):
        // migrate into the body field when it is present and empty,
        // then drop the unattributed value.
        const legacy = loadDraft(window.localStorage, userId, key);
        if (legacy) {
            const body = form.querySelector('textarea[name="body"]');
            if (body && body.value === '') {
                body.value = legacy;
            }
            clearDraft(window.localStorage, userId, key);
        }
    }

    function watch(form) {
        const key = form.getAttribute('data-draft-key');
        const fields = formFields(form);
        if (!key || fields.length === 0) {
            return;
        }

        const userId = currentUserId();

        fields.forEach(function (field) {
            field.addEventListener('input', () => {
                saveDraft(window.localStorage, userId, key + ':' + field.name, field.value);
            });
        });

        form.addEventListener('submit', () => {
            fields.forEach(function (field) {
                clearDraft(window.localStorage, userId, key + ':' + field.name);
            });
            clearDraft(window.localStorage, userId, key);

            form.querySelectorAll('[data-disable-on-submit]').forEach((button) => {
                button.disabled = true;
                button.classList.add('opacity-50');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Chat manages its own single-field draft (chat-room.js): the
        // generic per-field manager must not double-bind that form.
        document.querySelectorAll('form[data-draft-key]:not([data-chat-form])').forEach((form) => {
            restore(form);
            watch(form);
        });

        // Double-submit protection for forms without drafts.
        document.querySelectorAll('form:not([data-draft-key])').forEach((form) => {
            form.addEventListener('submit', () => {
                form.querySelectorAll('[data-disable-on-submit]').forEach((button) => {
                    button.disabled = true;
                    button.classList.add('opacity-50');
                });
            });
        });
    });
})();
