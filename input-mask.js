
(function () {
    'use strict';

    var NAME_FALLBACK = /^[a-zA-Z\s\-'\.]+$/;

    function nameRegex() {
        if (typeof ValidationRules !== 'undefined' && ValidationRules.NAME_PATTERN) {
            return ValidationRules.NAME_PATTERN;
        }
        return NAME_FALLBACK;
    }

    var MODES = {
        name: function (v) { return nameRegex().test(v); },
        initial: function (v) { return /^[a-zA-Z]\.?$/.test(v); },
        suffix: function (v) { return /^[a-zA-Z\s.,\-']*$/.test(v); },
        text_only: function (v) { return /^[a-zA-Z\s.,\-']*$/.test(v); },
        digits: function (v) { return /^[0-9+()\s\-]*$/.test(v); }
    };

    var NAME_FIELDS = ['first_name', 'last_name', 'info-first-name', 'info-last-name', 'staff-first-name', 'staff-last-name'];
    var INITIAL_FIELDS = ['middle_name', 'info-middle-name', 'staff-middle-name'];
    var SUFFIX_FIELDS = ['suffix', 'info-suffix', 'staff-suffix'];
    var TEXT_FIELDS = ['birth_place', 'info-birth-place', 'staff-birth-place', 'citizenship', 'info-citizenship', 'staff-citizenship'];

    function inferMode(el) {
        var explicit = el.getAttribute('data-mask');
        if (explicit && MODES[explicit]) return explicit;
        var key = el.getAttribute('name') || el.id || '';
        if (NAME_FIELDS.indexOf(key) !== -1) return 'name';
        if (INITIAL_FIELDS.indexOf(key) !== -1) return 'initial';
        if (SUFFIX_FIELDS.indexOf(key) !== -1) return 'suffix';
        if (TEXT_FIELDS.indexOf(key) !== -1) return 'text_only';
        return null;
    }

    function filterValue(value, mode) {
        if (mode === 'initial') {
            var letters = value.replace(/[^a-zA-Z]/g, '');
            if (letters.length === 0) return '';
            return letters.charAt(0) + (/\./.test(value) ? '.' : '');
        }
        if (mode === 'digits') {
            return value.replace(/[^0-9+()\s\-]/g, '');
        }
        if (mode === 'suffix' || mode === 'text_only') {
            return value.replace(/[^a-zA-Z\s.,\-']/g, '');
        }
        return value.replace(/[^a-zA-Z\s\-'\.]/g, '');
    }

    function attach(el) {
        var mode = inferMode(el);
        if (!mode) return;
        if (el.__inputMaskBound) return;
        el.__inputMaskBound = true;

        el.addEventListener('beforeinput', function (ev) {
            if (!ev.data) return;
            var start = el.selectionStart, end = el.selectionEnd;
            var next = el.value.slice(0, start) + ev.data + el.value.slice(end);
            if (!MODES[mode](next)) {
                ev.preventDefault();
            }
        });

        el.addEventListener('input', function () {
            var filtered = filterValue(el.value, mode);
            if (filtered === el.value) return;
            var start = el.selectionStart;
            var removed = el.value.length - filtered.length;
            el.value = filtered;
            try {
                var pos = Math.max(0, start - removed);
                el.setSelectionRange(pos, pos);
            } catch (e) {  }
        });
    }

    function applyDateLimits(root) {
        var dateInputs = root.querySelectorAll('input[type="date"]');
        if (!dateInputs.length) return;
        var cutoff = (typeof ValidationRules !== 'undefined' && ValidationRules.minimumBirthdate)
            ? ValidationRules.minimumBirthdate()
            : (function () { var t = new Date(); return new Date(t.getFullYear() - 15, t.getMonth(), t.getDate()); })();
        var y = cutoff.getFullYear();
        var m = String(cutoff.getMonth() + 1).padStart(2, '0');
        var d = String(cutoff.getDate()).padStart(2, '0');
        var maxStr = y + '-' + m + '-' + d;

        Array.prototype.forEach.call(dateInputs, function (el) {
            var key = (el.getAttribute('name') || el.id || '').toLowerCase();
            if (key.indexOf('birth') !== -1 || key.indexOf('dob') !== -1 || el.getAttribute('data-birthdate') !== null) {
                el.setAttribute('max', maxStr);
            }
        });
    }

    function apply(root) {
        root = root || document;
        var nodes = root.querySelectorAll('input[type="text"], input[type="tel"], input:not([type])');
        Array.prototype.forEach.call(nodes, attach);
        applyDateLimits(root);
    }

    window.InputMask = { apply: apply };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { apply(document); });
    } else {
        apply(document);
    }
})();
