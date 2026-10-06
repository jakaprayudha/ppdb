# PPDB — autentikasi dan registrasi peserta

PHP, HTML, CSS, JavaScript vanilla, dan SQLite. Tidak memerlukan Composer atau npm.

## Menjalankan secara lokal

Persyaratan: PHP 8.2+ dengan ekstensi `pdo_sqlite`, `mbstring`, dan `fileinfo`.

```sh
php -S 127.0.0.1:8000 -t public public/router.php
```

Buka http://127.0.0.1:8000. Database dan tabel dibuat otomatis di `storage/app.sqlite`.
Di VS Code tersedia juga task **SPMB: server lokal** setelah konfigurasi task dibuat.
Gunakan data uji saja; belum siap untuk penerimaan murid nyata.

### Domain lokal Laravel Herd

Versi PHP CLI dan versi PHP situs Herd bisa berbeda. Situs `ppdb.test` harus memakai
PHP 8.2+; PHP 8.0 tidak mendukung sintaks modul registrasi. Dari folder proyek:

```sh
herd isolate 8.4 --site=ppdb
herd isolated
```

Perintah ini mengatur versi khusus situs PPDB, bukan versi default semua situs.
Buka http://ppdb.test/register. Jika sesudah login muncul “Layanan belum tersedia”,
periksa versi situs dan log Herd; pesan `[SPMB]` mencatat penyebab sebenarnya.
Aplikasi menolak runtime di bawah PHP 8.2 sejak bootstrap dengan pesan jelas pada log.
Untuk tautan pemulihan pada domain Herd, atur environment web server
`APP_URL=http://ppdb.test`; default `APP_URL` tetap alamat server lokal port 8000.

## Fitur tahap ini

- Register akun wali, login, dashboard akun, logout melalui POST.
- Validasi server, password hash, CSRF, prepared statements, cookie HttpOnly/SameSite,
  regenerasi session ID, pembatasan percobaan per identitas dan alamat IP.
- Pemulihan password dengan token acak 256-bit, hash token di database, kedaluwarsa
  30 menit, sekali pakai, dan invalidasi seluruh sesi lama setelah reset.
- Sesi berakhir setelah 30 menit tidak aktif atau maksimum 12 jam.
- Audit register/login/logout/reset tanpa password atau token mentah.
- Layout responsif dan formulir tetap bekerja tanpa JavaScript.
- Profil beberapa peserta untuk satu akun wali.
- Konfigurasi sekolah, penyelenggara, jenjang, tahun ajaran, jadwal, zona waktu, jalur,
  pemberitahuan privasi, kontak bantuan, dan dokumen per jalur melalui CLI admin lokal.
- Formulir empat langkah: peserta, wali/alamat/jalur, dokumen, tinjau/kirim.
- Draf tersimpan ke SQLite melalui tombol **Simpan draf** atau **Simpan & lanjut**.
  Tidak ada autosave; browser memperingatkan perubahan yang belum disimpan jika JavaScript aktif.
- Upload PDF/JPG/PNG, checklist wajib/opsional, pratinjau gambar dan unduh PDF untuk ditinjau.
- Pengiriman idempoten, nomor pendaftaran, tanda terima yang dapat dicetak/disimpan sebagai PDF
  lewat dialog cetak browser, serta status awal menunggu verifikasi.
- Optimistic locking mencegah tab/perangkat lama menimpa draf; transaksi SQLite mengunci
  pengiriman dan penerbitan nomor pendaftaran. Data/dokumen tidak dapat diubah setelah dikirim.

Antarmuka menggunakan identitas PPDB, teks layanan pemerintah yang formal, dan palet
biru pada halaman autentikasi serta dashboard. Nama instansi, logo, dan wilayah
penyelenggara belum ditetapkan; lengkapi identitas dan otorisasi penyelenggara sebelum publikasi.

Register publik tidak memberikan akses panitia. Tenancy, peran staf, dan MFA
akan dibangun bersama modul penyelenggara sebelum akses panitia diaktifkan.
Email belum diverifikasi; verifikasi kepemilikan email wajib ditambahkan sebelum data
pendaftaran nyata dikaitkan ke akun.
**Seluruh perubahan pada modul registrasi diblokir pada `APP_ENV=production`.**
Modul ini adalah pilot pengembangan, bukan layanan penerimaan pemerintah yang siap dibuka.

## Mencoba registrasi peserta

```sh
php bin/admissions.php demo
php bin/admissions.php list
```

Perintah `demo` membuat satu periode berlabel **DEMO**, terbuka sejak sehari sebelumnya
sampai 30 hari sesudah perintah dijalankan, dengan sekolah/penyelenggara fiktif.
Perintah ini tidak berjalan otomatis saat server dimulai dan tidak boleh dijalankan di produksi.
Pengulangan dengan kode yang sama ditolak agar jadwal dan persyaratan tidak ditimpa.

1. Register/login akun wali, lalu pilih **Profil peserta → Tambah peserta**.
2. Nama peserta wajib saat menyimpan profil; data lain dapat dilengkapi bertahap.
3. Buka **Pendaftaran**, pilih periode demo dan jalur simulasi.
4. Simpan data pada tiap langkah; unggah dokumen fiktif pada checklist.
5. Tinjau seluruh isian dan pemberitahuan privasi, lalu konfirmasi dan kirim.
6. Simpan nomor dan cetak tanda terima. Status menunggu verifikasi **bukan** keputusan diterima.

Satu draf per profil dan periode; pengiriman ulang tidak menggandakan pendaftaran/nomor.
Satu periode mewakili satu sekolah dan satu pilihan, belum pemilihan lintas sekolah.
Data profil disalin saat draf dibuat. Mengedit profil tidak mengubah draf atau snapshot
pendaftaran yang sudah dikirim. Data dalam draf dapat diedit tersendiri sebelum pengiriman.
Penyimpanan draf, upload, dan pengiriman hanya diizinkan saat jadwal periode terbuka.
Sesudah tenggat, draf dan tanda terima tetap dapat dibaca.

### Fokus Kabupaten Serdang Bedagai

```sh
php bin/admissions.php sergai
```

Perintah development ini menyiapkan **40 periode DEMO untuk SMP negeri umum** di
Kabupaten Serdang Bedagai. Nama, NPSN, dan kecamatan memakai snapshot
[Referensi Data Kemendikdasmen](https://referensi.data.kemendikdasmen.go.id/pendidikan/dikdas/072100/2/jf/6/s1)
tanggal 6 Oktober 2026 yang tersimpan di `data/serdang-bedagai-smp.json`.
Seluruh 17 halaman kecamatan diperiksa. Daftar per kecamatan juga memuat satu
Sekolah Rakyat (NPSN 75683280), dicatat dalam snapshot tetapi **tidak dibuatkan periode**
karena mekanisme penerimaannya khusus.

Nama sekolah merupakan data resmi, tetapi jadwal, checklist, jalur, dan periode tetap
simulasi. Tidak ada klaim otorisasi dinas, partisipasi sekolah, atau kesesuaian Juknis
Serdang Bedagai. Kuota dan jadwal nyata masih harus disahkan penyelenggara.

Periode lain, termasuk contoh swasta, diarsipkan dari pilihan baru. Draf/tanda terima
lama tetap dapat dibaca; periode arsip tidak bisa menerima perubahan, unggahan, atau
pengiriman baru. Tidak ada akun, pendaftaran, atau dokumen yang dihapus.
Menjalankan ulang `sergai` tidak menggandakan sekolah dan tidak mengubah jadwal/snapshot
periode yang sudah ada. Aktivasi daftar dilakukan setelah seluruh sekolah selesai disiapkan.
Halaman penerimaan menyediakan pencarian nama/NPSN dan filter kecamatan.

### Template jalur negeri dan swasta

```sh
php bin/admissions.php template negeri SMP
php bin/admissions.php template swasta SMP
```

Jenjang dapat diganti dengan `SD` atau `SMA`. Perintah membuat periode baru berlabel
DEMO, bukan mengubah periode simulasi atau pendaftaran yang sudah ada.
Untuk mencoba jalur baru, buat draf pada periode template yang sesuai.

- Negeri: **Domisili (sebelumnya zonasi), Afirmasi, Prestasi, Mutasi**, berdasarkan
  baseline [Permendikdasmen No. 3 Tahun 2025](https://peraturan.go.id/files/Permendikdasmen-no-3-tahun-2025.pdf).
  Template kelas 1 SD tidak menyediakan Prestasi.
- Swasta mandiri: contoh **Reguler/Mandiri, Prestasi sekolah, Beasiswa/Bantuan biaya**.
  Bukan daftar nasional wajib; sekolah boleh menyesuaikan. Template SD tidak
  menyediakan jalur Prestasi, dan tidak membuat tes akademik masuk SD.
- Bukti afirmasi/mutasi/prestasi mengikuti kategori; satu label alternatif tidak
  berarti seluruh bukti kategori wajib sekaligus. Checklist demo wajib memakai
  berkas fiktif dan ditinjau ulang terhadap Juknis/kebijakan sekolah sebelum digunakan.
- Kuota, batas wilayah, prioritas, seleksi, biaya swasta, dan program bantuan belum
  diaktifkan. Tidak ada klaim bahwa template memenuhi Juknis daerah tertentu.
- `admission_mode` opsional untuk konfigurasi lama: `public_spmb` atau
  `private_independent`. Mode negeri membatasi kode jalur nasional dan menolak
  Prestasi untuk SD. Mode ini belum menjadi validasi kepatuhan nasional lengkap.

### Import konfigurasi penyelenggara

Siapkan JSON memakai struktur berikut, dengan seluruh teks/jadwal/persyaratan ditinjau
penyelenggara. Contoh ini **bukan Juknis atau jalur penerimaan negeri yang sah**:

```json
{
  "code": "contoh-smp-2026-v1",
  "organizer": "Penyelenggara Contoh",
  "school": "Sekolah Contoh",
  "level": "SMP",
  "academic_year": "2026/2027",
  "timezone": "Asia/Jakarta",
  "opens_at": "2026-10-01 08:00:00",
  "closes_at": "2026-10-31 16:00:00",
  "is_demo": true,
  "privacy_notice": "Hanya data uji untuk simulasi teknis. Jangan memasukkan data anak yang sebenarnya.",
  "help_contact": "Hubungi pengelola lokal.",
  "rule_reference": "Simulasi; bukan Juknis resmi.",
  "pathways": [
    {
      "code": "simulasi",
      "name": "Jalur simulasi",
      "description": "Pengujian alur pendaftaran.",
      "documents": [
        {"code": "identitas-demo", "label": "Identitas fiktif", "required": true},
        {"code": "tambahan-demo", "label": "Dokumen tambahan fiktif", "required": false}
      ]
    }
  ]
}
```

```sh
php bin/admissions.php import /path/absolut/periode.json
php bin/admissions.php list
```

Konfigurasi divalidasi: kode unik, tahun ajaran berurutan, jenjang SD/SMP/SMA,
zona waktu Indonesia, tanggal valid, tutup sesudah buka, kode jalur/dokumen unik,
dan penandaan wajib dokumen. Waktu input ditafsirkan dalam zona periode dan disimpan sebagai
timestamp UTC. Konfigurasi yang terbit tidak diedit/ditimpa melalui CLI; gunakan kode baru.
Snapshot konfigurasi versi 1 disimpan pada pendaftaran yang dikirim.
**Import hanya menyiapkan formulir/jadwal, tidak memvalidasi kuota, kelayakan usia, aturan
nasional/Juknis, pemeringkatan, atau persetujuan kebijakan.** NISN opsional; sekolah asal
wajib saat mengirim ke SMP/SMA. Tidak ada verifikasi identitas nasional otomatis.

### Dokumen privat

Berkas disimpan dengan nama acak di `storage/documents/`, bukan folder publik.
Endpoint `/documents/{id}` memeriksa sesi dan kepemilikan aplikasi; menebak ID tidak
memberikan akses. Maksimal 2 MB/file dan 20 megapiksel/gambar, MIME/ekstensi diperiksa,
token CSRF wajib, dan hash SHA-256 diperiksa saat unduh dan pengiriman.
PDF disajikan sebagai unduhan, bukan HTML/iframe aktif; gambar dapat dipratinjau.
Atur `upload_max_filesize` minimal `2M` dan `post_max_size` minimal `4M`; respons
413/422 ditampilkan jika ukuran melewati batas. Maksimal 30 percobaan upload per akun
dalam 15 menit.

Penggantian/penghapusan dokumen dari checklist mencabut akses ke versi lama, tetapi
salinan privat dan metadata tetap disimpan untuk audit. Tidak ada pembersihan retensi
otomatis pada tahap ini. Tentukan dan implementasikan retensi sebelum data nyata.
Pemindaian malware/antivirus, enkripsi at-rest, dan verifikasi panitia belum tersedia.

## Email pemulihan lokal

Default pengembangan menggunakan `MAIL_TRANSPORT=file`. Setelah mengirim permintaan
lupa password untuk akun terdaftar, buka berkas `.eml` terbaru di `storage/mail/` dari
komputer pengembang dan salin tautannya. Berkas ini **privat**, bukan endpoint publik,
dan tidak berarti email telah dikirim ke internet. Respons halaman tidak mengungkap
apakah email terdaftar. Tautan reset tidak ditampilkan di browser.

Token email lokal adalah rahasia. Jangan membagikan, mengunggah, atau memasukkan folder
`storage/` ke version control. Hapus berkas email uji yang sudah tidak diperlukan.

## Konfigurasi

Konfigurasi dibaca dari environment, bukan dari file `.env`.

| Variabel | Default lokal | Keterangan |
|---|---|---|
| `APP_ENV` | `development` | `development` atau `production` |
| `APP_URL` | `http://127.0.0.1:8000` | Origin kanonis tanpa path atau trailing slash; tautan reset selalu memakai origin ini |
| `APP_STORAGE` | folder `storage/` proyek | Path absolut penyimpanan privat, di luar document root |
| `MAIL_TRANSPORT` | `file` | `file` untuk lokal, `mail` untuk PHP `mail()` |
| `MAIL_FROM` | `noreply@example.test` | Alamat pengirim valid; gunakan domain nyata saat produksi |

Jika memakai port berbeda, sesuaikan `APP_URL`.
Produksi menolak URL non-HTTPS dan transport email berbasis file.
Pengiriman `mail()` membutuhkan MTA/relay yang dikonfigurasi di server. Kegagalan pengiriman
menghasilkan respons 503 dan log server, bukan notifikasi sukses.

### Deployment

Document root **harus** menunjuk ke `public/`, bukan root proyek. Konfigurasikan web server
agar aset dilayani langsung dan route lainnya diteruskan ke `public/index.php`, dengan query
string tetap dipertahankan. Router PHP bawaan hanya untuk pengembangan.
Redaksi query string pada access log untuk route reset password agar token pemulihan
tidak ikut tersimpan pada log web server, proxy, atau layanan monitoring.

SQLite, email lokal, dan sesi harus berada di luar document root, dengan hak akses hanya
untuk proses aplikasi. Jangan gunakan server development PHP untuk produksi. Gunakan HTTPS,
`APP_ENV=production`, `APP_URL=https://domain-anda`, `MAIL_TRANSPORT=mail`, dan `MAIL_FROM`
yang sesuai. Jika berada di belakang proxy, origin HTTPS tetap harus dikonfigurasi; aplikasi
tidak mempercayai header forwarded dari klien. Rate limit IP memakai `REMOTE_ADDR`; proxy
harus mengatur alamat klien terpercaya di web server jika diperlukan.

SQLite memakai WAL dan busy timeout 5 detik. Uji beban tulis sebelum membuka penerimaan.
Backup database aktif dengan SQLite backup API, bukan sekadar menyalin file `.sqlite`
saat masih ada transaksi. Enkripsi volume/backup adalah tanggung jawab deployment.
Tetapkan retensi akun, sesi, audit, dan token; data privat tidak boleh disimpan tanpa batas
pada deployment nyata. Identitas pengelola, kontak bantuan, kebijakan privasi final,
verifikasi email, monitoring, dan pemulihan backup juga wajib diselesaikan sebelum rilis.

## Validasi

```sh
find app public bin -name '*.php' -exec php -l {} \;
python3 -m unittest discover -s tests -p '*_flow.py' -v
```

Tes HTTP menggunakan database sementara dan server di port kosong; tidak mengubah akun lokal.
Menguji register/login, CSRF, pembatasan akses/percobaan, pemulihan, kedaluwarsa, penggunaan
ulang token, dan invalidasi sesi lama.
Tes juga memeriksa regenerasi ID sesi, pembatasan akses berkas privat, dan penolakan
konfigurasi produksi yang tidak aman.
Tes registrasi mencakup profil/draf, snapshot, validasi, idempotensi, konflik versi,
isolasi wali, CSRF, upload valid/palsu/terlalu besar, replace/delete, integritas file,
checklist wajib, tenggat, tanda terima, dan import konfigurasi.

## Berikutnya

Sesuai [PRD](prd.md): verifikasi email, tenancy dan hak akses staf/MFA, panel verifikasi
panitia dengan perbaikan berkas, aturan penerimaan yang disetujui, seleksi, hasil,
sanggah, dan daftar ulang. Portal informasi publik lengkap juga belum tersedia;
daftar periode pada tahap ini berada di area wali yang sudah login.
