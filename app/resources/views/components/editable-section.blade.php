{{-- Read-only-until-Edit behaviour for admin forms. Include ONCE per page
     (it is auto-included site-wide from the admin layout).

     Opt a form in by marking it `data-editable`. On load every such form is
     locked: its inputs are disabled and its submit ("Save") button is hidden
     behind an injected "Edit" button. Clicking Edit unlocks the fields,
     captures their original values, and reveals Save + Cancel. Cancel restores
     the originals and re-locks. On Save the script computes a field-level diff
     (old → new), refuses an empty save, and hands the diff to the global
     confirm modal via `data-confirm-changes` so the admin sees exactly what is
     changing before it is written.

     Markup contract (minimal — controls are generated): a form tagged
     data-editable + data-confirm/-title; one or more inputs each carrying a
     data-field-label (repeat per field); and a single submit button labelled
     "Save". Add data-sensitive to any PII field (PAN/Aadhaar/bank) so its
     values are redacted in the diff. --}}
<script>
(function () {
    var FIELD_SEL = 'input, select, textarea';

    // Editable fields = visible inputs that carry data (skip hidden + CSRF).
    function fields(form) {
        return Array.prototype.filter.call(form.querySelectorAll(FIELD_SEL), function (el) {
            return el.type !== 'hidden' && el.name !== '_token';
        });
    }

    function showControls(form, editing) {
        form.querySelectorAll('[data-editable-save], [data-editable-cancel]').forEach(function (b) {
            b.classList.toggle('hidden', !editing);
        });
        form.querySelectorAll('[data-editable-edit]').forEach(function (b) {
            b.classList.toggle('hidden', editing);
        });
    }

    function lock(form) {
        fields(form).forEach(function (el) { el.disabled = true; });
        form.dataset.editing = 'false';
        showControls(form, false);
    }

    function unlock(form) {
        fields(form).forEach(function (el) {
            el.disabled = false;
            el._orig = el.type === 'checkbox' ? el.checked : el.value;
        });
        form.dataset.editing = 'true';
        showControls(form, true);
        var first = fields(form)[0];
        if (first) { try { first.focus(); } catch (e) {} }
    }

    function cancel(form) {
        fields(form).forEach(function (el) {
            if (el._orig === undefined) { return; }
            if (el.type === 'checkbox') { el.checked = el._orig; }
            else { el.value = el._orig; }
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
        lock(form);
    }

    // Field-level diff against the values captured when Edit was pressed.
    function diff(form) {
        var changes = [];
        fields(form).forEach(function (el) {
            var label = el.getAttribute('data-field-label') || el.name || 'Field';
            if (el.type === 'checkbox') {
                if (el._orig !== el.checked) {
                    changes.push({ label: label, from: el._orig ? 'Yes' : 'No', to: el.checked ? 'Yes' : 'No' });
                }
                return;
            }
            if (el._orig !== undefined && String(el._orig) !== String(el.value)) {
                if (el.hasAttribute('data-sensitive')) {
                    changes.push({ label: label, from: '••••', to: 'updated' });
                } else {
                    changes.push({
                        label: label,
                        from: el._orig === '' ? '(empty)' : el._orig,
                        to: el.value === '' ? '(empty)' : el.value
                    });
                }
            }
        });
        return changes;
    }

    document.querySelectorAll('form[data-editable]').forEach(function (form) {
        var saveBtn = form.querySelector('button[type="submit"]');
        if (!saveBtn) { return; }
        saveBtn.setAttribute('data-editable-save', '');

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.setAttribute('data-editable-edit', '');
        editBtn.textContent = 'Edit';
        editBtn.className = saveBtn.className;

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.setAttribute('data-editable-cancel', '');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.className = 'px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-gray-700 text-sm font-medium hover:bg-gray-50';

        saveBtn.parentNode.insertBefore(editBtn, saveBtn);
        saveBtn.parentNode.insertBefore(cancelBtn, saveBtn.nextSibling);

        editBtn.addEventListener('click', function () { unlock(form); });
        cancelBtn.addEventListener('click', function () { cancel(form); });

        lock(form);
    });

    // Capture phase runs before the form-level confirm-modal listener, so the
    // diff is attached before the modal reads it. An empty diff is refused here.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form[data-editable]')) { return; }
        if (form.dataset.confirmed === 'true') { return; }
        var changes = diff(form);
        if (changes.length === 0) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (typeof window.showToast === 'function') { window.showToast('No changes to save.', 'info'); }
            return;
        }
        form.dataset.confirmChanges = JSON.stringify(changes);
    }, true);
})();
</script>
