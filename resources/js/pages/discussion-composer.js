// Discussion composers: preserve drafts across validation failures and
// reconnects (localStorage), and prevent double submission.
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

        try {
            const draft = window.localStorage.getItem('composer:' + key);
            if (draft) {
                field.value = draft;
            }
        } catch (_) {
            // Private browsing: drafts simply do not persist.
        }
    }

    function watch(form) {
        const key = form.getAttribute('data-draft-key');
        const field = form.querySelector('textarea[name="body"]');
        if (!key || !field) {
            return;
        }

        field.addEventListener('input', () => {
            try {
                if (field.value === '') {
                    window.localStorage.removeItem('composer:' + key);
                } else {
                    window.localStorage.setItem('composer:' + key, field.value);
                }
            } catch (_) {}
        });

        form.addEventListener('submit', () => {
            try {
                window.localStorage.removeItem('composer:' + key);
            } catch (_) {}

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
