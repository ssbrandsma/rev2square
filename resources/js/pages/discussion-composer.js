// Discussion composers: preserve drafts across validation failures and
// reconnects (localStorage, namespaced by account), and prevent double
// submission.
import { currentUserId } from '../echo.js';
import { clearDraft, loadDraft, saveDraft } from '../drafts.js';

(function () {
    'use strict';

    function restore(form) {
        const key = form.getAttribute('data-draft-key');
        if (!key) {
            return;
        }

        const field = form.querySelector('textarea[name="body"]');
        if (!field || field.value !== '') {
            return;
        }

        const draft = loadDraft(window.localStorage, currentUserId(), key);
        if (draft) {
            field.value = draft;
        }
    }

    function watch(form) {
        const key = form.getAttribute('data-draft-key');
        const field = form.querySelector('textarea[name="body"]');
        if (!key || !field) {
            return;
        }

        const userId = currentUserId();

        field.addEventListener('input', () => {
            saveDraft(window.localStorage, userId, key, field.value);
        });

        form.addEventListener('submit', () => {
            clearDraft(window.localStorage, userId, key);

            form.querySelectorAll('[data-disable-on-submit]').forEach((button) => {
                button.disabled = true;
                button.classList.add('opacity-50');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form[data-draft-key]').forEach((form) => {
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
