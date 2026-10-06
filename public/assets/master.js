'use strict';

document.querySelectorAll('[data-pathway-editor]').forEach((editor) => {
    const routes = editor.querySelector('[data-pathways]');
    const status = editor.querySelector('[data-editor-status]');
    const form = editor.closest('form');
    const renumber = () => {
        routes.querySelectorAll('[data-pathway]').forEach((route, i) => {
            route.querySelector('legend').textContent = `Jalur ${i + 1}`;
            route.querySelectorAll('[data-route-field]').forEach(input => {
                input.name = `pathways[${i}][${input.dataset.routeField}]`;
            });
            route.querySelectorAll('[data-document]').forEach((doc, j) => {
                doc.querySelectorAll('[data-doc-field]').forEach(input => {
                    input.name = `pathways[${i}][documents][${j}][${input.dataset.docField}]`;
                });
            });
        });
        form.dispatchEvent(new Event('input', {bubbles: true}));
    };
    const makeDocument = (data = {code: '', label: '', required: true}) => {
        const row = document.createElement('div');
        row.className = 'document-editor-row';
        row.dataset.document = '';
        row.innerHTML = '<div class="field"><label>Kode dokumen<input data-doc-field="code" maxlength="40" required></label></div>'
            + '<div class="field"><label>Nama dokumen<input data-doc-field="label" maxlength="150" required></label></div>'
            + '<label class="document-required"><input type="checkbox" data-doc-field="required" value="1"> Wajib</label>'
            + '<button type="button" class="button button-outline button-danger" data-remove-document>Hapus dokumen</button>';
        row.querySelector('[data-doc-field="code"]').value = data.code;
        row.querySelector('[data-doc-field="label"]').value = data.label;
        row.querySelector('[data-doc-field="required"]').checked = data.required;
        return row;
    };
    const makeRoute = (data = {code: '', name: '', description: '', documents: []}) => {
        const row = document.createElement('fieldset');
        row.className = 'pathway-editor-row';
        row.dataset.pathway = '';
        row.innerHTML = '<legend></legend><div class="form-grid">'
            + '<div class="field"><label>Kode jalur<input data-route-field="code" maxlength="40" required></label></div>'
            + '<div class="field"><label>Nama jalur<input data-route-field="name" maxlength="100" required></label></div>'
            + '<div class="field field-wide"><label>Deskripsi<textarea data-route-field="description" maxlength="2000" rows="2"></textarea></label></div></div>'
            + '<div data-documents></div><div class="action-row"><button type="button" class="button button-outline" data-add-document>Tambah dokumen</button>'
            + '<button type="button" class="button button-outline button-danger" data-remove-pathway>Hapus jalur</button></div>';
        for (const key of ['code', 'name', 'description']) row.querySelector(`[data-route-field="${key}"]`).value = data[key];
        data.documents.forEach(doc => row.querySelector('[data-documents]').append(makeDocument(doc)));
        return row;
    };
    editor.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        status.textContent = '';
        if (button.hasAttribute('data-add-pathway')) {
            if (routes.children.length >= 20) {
                status.textContent = 'Maksimal 20 jalur.';
                return;
            }
            const row = makeRoute();
            routes.append(row);
            row.querySelector('input').focus();
        } else if (button.hasAttribute('data-remove-pathway')) {
            if (routes.children.length <= 1) {
                status.textContent = 'Minimal satu jalur harus tersedia.';
                return;
            }
            button.closest('[data-pathway]').remove();
        } else if (button.hasAttribute('data-add-document')) {
            const list = button.closest('[data-pathway]').querySelector('[data-documents]');
            if (list.children.length >= 10) {
                status.textContent = 'Maksimal 10 dokumen per jalur.';
                return;
            }
            const row = makeDocument();
            list.append(row);
            row.querySelector('input').focus();
        } else if (button.hasAttribute('data-remove-document')) {
            button.closest('[data-document]').remove();
        } else if (button.hasAttribute('data-load-pathway-template')) {
            if (!window.confirm('Ganti seluruh jalur dan persyaratan di formulir ini dengan template? Isian belum tersimpan akan diganti.')) return;
            const select = editor.querySelector('[data-pathway-template]');
            const data = JSON.parse(select.selectedOptions[0].dataset.templatePathways);
            routes.replaceChildren(...data.map(makeRoute));
            status.textContent = 'Template diterapkan. Tinjau dan simpan periode untuk menyimpan perubahan.';
        } else return;
        renumber();
    });
    form.querySelector('[data-period-school]')?.addEventListener('change', event => {
        const school = event.target.selectedOptions[0];
        if (!school.dataset.mode) return;
        form.elements.namedItem('admission_mode').value = school.dataset.mode;
        editor.querySelector('[data-pathway-template]').value = `${school.dataset.mode === 'public_spmb' ? 'negeri' : 'swasta'}-${school.dataset.level}`;
        status.textContent = 'Mode disesuaikan dengan sekolah. Periksa jalur atau terapkan template yang sesuai jenjang.';
    });
});

document.querySelectorAll('.master-dropdown').forEach(dropdown => {
    dropdown.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            dropdown.open = false;
            dropdown.querySelector('summary').focus();
        }
    });
    document.addEventListener('click', event => {
        if (!dropdown.contains(event.target)) dropdown.open = false;
    });
});
