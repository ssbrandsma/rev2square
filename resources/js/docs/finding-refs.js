// Finding-reference picker: each row shows the target controls for
// its chosen kind only. Progressive enhancement — with JS disabled
// all controls stay visible and the server still validates.
(function () {
    'use strict';

    function syncRow(row) {
        const kind = row.querySelector('[data-ref-kind]');
        const current = kind ? kind.value : '';
        row.querySelectorAll('[data-ref-for]').forEach(function (control) {
            control.style.display = control.getAttribute('data-ref-for') === current ? '' : 'none';
        });
    }

    function initFindingRefs(scope) {
        scope.querySelectorAll('[data-finding-refs]').forEach(function (root) {
            root.querySelectorAll('[data-ref-row]').forEach(function (row) {
                const kind = row.querySelector('[data-ref-kind]');
                if (kind) {
                    kind.addEventListener('change', function () { syncRow(row); });
                }
                syncRow(row);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initFindingRefs(document);
    });
})();
