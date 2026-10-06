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

document.querySelectorAll('[data-location-control]').forEach((control) => {
    const button = control.querySelector('[data-get-location]');
    const consent = control.querySelector('[data-location-consent]');
    const status = control.querySelector('[data-location-status]');
    const form = control.closest('form');
    const fields = {province: 'Provinsi', city: 'Kabupaten / kota', district: 'Kecamatan', village: 'Desa / kelurahan', postal_code: 'Kode pos'};
    button.addEventListener('click', async () => {
        if (control.dataset.locationReady !== 'true') {
            status.textContent = 'Layanan lokasi internal belum dikonfigurasi. Isi alamat secara manual sementara.';
            return;
        }
        if (!consent.checked) {
            status.textContent = 'Centang persetujuan penggunaan lokasi terlebih dahulu.';
            consent.focus();
            return;
        }
        if (!window.isSecureContext || !navigator.geolocation) {
            status.textContent = 'Lokasi memerlukan HTTPS atau localhost dan browser yang mendukung GPS. Isi alamat manual jika tidak tersedia.';
            return;
        }
        button.disabled = true;
        status.textContent = 'Meminta izin dan mengambil lokasi perangkat…';
        const controller = new AbortController();
        let timeout;
        try {
            const position = await new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true, timeout: 15000, maximumAge: 0
                });
            });
            if (!consent.checked) {
                status.textContent = 'Persetujuan dibatalkan. Koordinat tidak dikirim.';
                return;
            }
            status.textContent = 'Mencari alamat melalui layanan internal…';
            timeout = setTimeout(() => controller.abort(), 10000);
            const response = await fetch('/participants/location', {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams({
                    csrf: form.elements.namedItem('csrf').value,
                    location_consent: '1',
                    latitude: String(position.coords.latitude),
                    longitude: String(position.coords.longitude)
                })
            });
            if (!response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Sesi mungkin berakhir atau layanan tidak tersedia. Muat ulang halaman setelah menyimpan isian manual.');
            }
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Pengambilan alamat gagal. Coba lagi.');
            if (!result.address || Object.keys(fields).some(key => typeof result.address[key] !== 'string')) {
                throw new Error('Respons alamat tidak valid. Isi alamat manual atau hubungi pengelola.');
            }
            if (!consent.checked) {
                status.textContent = 'Persetujuan dibatalkan. Isian alamat tidak diubah.';
                return;
            }
            const missing = [];
            for (const [key, label] of Object.entries(fields)) {
                const value = result.address[key];
                if (!value) {
                    missing.push(label);
                    continue;
                }
                const input = form.elements.namedItem(key);
                input.value = value;
                input.dispatchEvent(new Event('input', {bubbles: true}));
            }
            status.textContent = 'Wilayah yang ditemukan telah diisi. Periksa kesesuaian dengan domisili peserta dan tekan Simpan profil. '
                + (missing.length ? `Tidak ditemukan: ${missing.join(', ')}; kolom tersebut tidak diubah, lengkapi atau periksa manual. ` : '')
                + 'Alamat jalan / nomor rumah tetap perlu diperiksa manual.';
        } catch (error) {
            if (error.code === 1) status.textContent = 'Izin lokasi ditolak. Izinkan melalui pengaturan browser atau isi alamat manual.';
            else if (error.code === 2) status.textContent = 'Lokasi perangkat tidak tersedia. Aktifkan layanan lokasi atau isi alamat manual.';
            else if (error.code === 3 || error.name === 'AbortError') status.textContent = 'Pengambilan lokasi atau alamat terlalu lama. Coba lagi atau isi alamat manual.';
            else if (error instanceof TypeError) status.textContent = 'Koneksi layanan lokasi gagal. Coba lagi atau isi alamat manual.';
            else status.textContent = error.message || 'Pengambilan lokasi gagal. Isi alamat manual.';
        } finally {
            clearTimeout(timeout);
            button.disabled = false;
        }
    });
});
