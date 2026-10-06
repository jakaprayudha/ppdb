'use strict';

document.querySelectorAll('[data-password]').forEach((button) => {
    const input = document.getElementById(button.dataset.password);
    if (!input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.firstChild.textContent = show ? 'Sembunyikan' : 'Tampilkan';
    });
});

const errorSummary = document.querySelector('[data-error-summary]');
if (errorSummary) errorSummary.focus();

document.querySelectorAll('[data-draft-form]').forEach((form) => {
    let changed = false;
    form.addEventListener('input', () => { changed = true; });
    form.addEventListener('change', () => { changed = true; });
    form.addEventListener('submit', () => { changed = false; });
    window.addEventListener('beforeunload', (event) => {
        if (!changed) return;
        event.preventDefault();
        event.returnValue = '';
    });
});

document.querySelectorAll('[data-file-limit]').forEach((input) => {
    const status = input.closest('.field').querySelector('[data-file-status]');
    input.addEventListener('change', () => {
        input.setCustomValidity('');
        const file = input.files[0];
        if (!file) {
            status.textContent = 'Berkas baru disimpan setelah tombol unggah ditekan.';
            return;
        }
        if (file.size > Number(input.dataset.fileLimit)) {
            input.setCustomValidity('Berkas terlalu besar. Maksimal 2 MB.');
            status.textContent = 'Berkas terlalu besar. Pilih berkas maksimal 2 MB.';
        } else {
            status.textContent = `${file.name} dipilih. Tekan unggah untuk menyimpannya.`;
        }
    });
});

document.querySelectorAll('[data-print]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', () => window.print());
});
