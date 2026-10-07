# PPDB — autentikasi dan registrasi peserta

PHP, HTML, CSS, JavaScript vanilla, dan SQLite. Tidak memerlukan Composer atau npm.

## Menjalankan secara lokal

Persyaratan: PHP 8.2+ dengan ekstensi `pdo_sqlite`, `mbstring`, `fileinfo`, dan
`openssl` (enkripsi secret MFA staf).

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
biru pada halaman autentikasi serta dashboard. Header akun wali dan menu navigasi
tetap terlihat saat halaman pendaftar digulir, dan disembunyikan saat mencetak.
Nama instansi, logo, dan wilayah
penyelenggara belum ditetapkan; lengkapi identitas dan otorisasi penyelenggara sebelum publikasi.

Register publik tidak memberikan akses panitia. Admin pusat, admin sekolah dan
verifikator tersedia untuk pengujian lokal dengan pembatasan sekolah/penugasan.
2FA opsional untuk staf dan wali, nonaktif secara default. Kepemilikan email staf diverifikasi lewat undangan atau tautan
verifikasi untuk admin lama. Wali dapat memverifikasi email melalui tautan **Akun
wali** di header; verifikasi email wali belum menjadi syarat alur pilot, tetapi
wajib dijadikan gate sebelum data nyata dikaitkan ke akun.
Persetujuan hasil seleksi belum tersedia. Persetujuan paket aturan operasional
oleh approver sekolah yang berbeda dari penyusun/pengaju sudah tersedia.
**Seluruh perubahan pada modul registrasi diblokir pada `APP_ENV=production`.**
Modul ini adalah pilot pengembangan, bukan layanan penerimaan pemerintah yang siap dibuka.

Label dokumen pada tampilan menggunakan nama biasa seperti “Kartu keluarga” dan
“Akta kelahiran”, tanpa akhiran instruksi demo. Periode uji ditandai
**Belum dibuka untuk penerimaan nyata**, termasuk pada tanda terima cetak.
Label konfigurasi lama dinormalisasi hanya saat ditampilkan; database, kode periode,
penanda `is_demo`, serta snapshot pendaftaran yang telah dikirim tidak diubah.
Perapian teks ini tidak mengaktifkan produksi atau mengesahkan jadwal/persyaratan.

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
Pada **Pendaftaran Anda**, tombol **Batal** tersedia hanya untuk draf, termasuk
draf periode diarsipkan/ditutup. Halaman konfirmasi dan persetujuan wajib sebelum
registrasi, riwayat draf, serta seluruh unggahan (aktif maupun lama) dihapus permanen.
Profil peserta tetap ada; pendaftaran terkirim tidak dapat dihapus dengan fitur ini.
Pembatalan memeriksa kepemilikan, CSRF, dan versi draf, serta diblokir pada produksi.
Audit akun hanya mencatat aksi pembatalan tanpa data peserta.
Penghapusan berkas memakai antrean persisten agar dapat dilanjutkan jika proses
terputus setelah penghapusan data registrasi. Jika pembersihan gagal, aplikasi
menampilkan kesalahan; pengelola menjalankan `php bin/cleanup-cancelled-documents.php`
dengan `APP_STORAGE` yang sama untuk menuntaskan antrean.
Penyimpanan draf, upload, dan pengiriman hanya diizinkan saat jadwal periode terbuka.
Sesudah tenggat, draf dan tanda terima tetap dapat dibaca.

## Admin pusat dan verifikasi peserta

```sh
php bin/admin.php seed
```

Perintah khusus development membuat akun uji `admin.pusat@example.test` dengan
password acak yang ditampilkan sekali di terminal. Masuk melalui `/login`;
akun diarahkan ke `/admin`. Simpan password secara privat, bukan di source code.
Sebelum portal terbuka, akun baru maupun admin lama diarahkan ke
`/account/security` hanya jika email belum terverifikasi atau 2FA yang dipilih
pengguna sudah aktif dan sesi belum lolos challenge. Setup 2FA tidak dipaksakan.
Pengulangan tidak mereset password atau mempromosikan akun wali yang kebetulan
memakai email tersebut. Jika lupa, gunakan pemulihan password. Tidak ada akun
admin yang dibuat otomatis saat aplikasi dijalankan.

Admin pusat memiliki akses **seluruh sekolah**, bukan hanya satu sekolah:

- **Dashboard:** jumlah draf, terkirim, antrean verifikasi, valid, perlu perbaikan,
  dan tidak valid; draf hanya dihitung, tidak dibuka untuk pemeriksaan.
- **Verifikasi:** daftar pendaftaran terkirim dengan pencarian, filter sekolah /
  periode dan status, paginasi 25 baris; lihat data snapshot serta dokumen privat
  dan catat hasil **Valid / Perlu perbaikan / Tidak valid**.
- **Master data:** sekolah, NPSN, kecamatan, periode, jadwal, jalur, dan persyaratan
  termasuk periode diarsipkan. Dropdown **Periode pendaftaran / Sekolah** membuka
  tabel masing-masing dengan pencarian, pagination 10 baris, detail, tambah, edit,
  hapus terkonfirmasi, dan arsip/aktivasi.
- **Akun & akses:** daftar admin pusat hanya-baca, undangan staf, daftar staf
  dengan pencarian/pagination 10 baris, edit peran, beberapa sekolah,
  aktif/nonaktif dan pembatalan undangan. Khusus admin pusat.
- **Profil akun:** verifikasi email, pilihan aktif/nonaktif 2FA, pengaturan/challenge MFA dan penggantian
  autentikator menggunakan password serta sesi yang sudah lolos MFA.
- **Audit:** 100 aktivitas terakhir; akses dokumen admin dan keputusan dicatat.

Catatan verifikasi wajib 5–2000 karakter, terlihat oleh wali pada status dan
tanda terima. Perubahan keputusan menyimpan reviewer, waktu, dan riwayat;
optimistic locking menolak keputusan dari tab yang versinya kedaluwarsa.
Verifikasi berkas bukan seleksi / keputusan diterima. Data terkirim dan snapshot
aturan tidak diubah. **Perlu perbaikan belum membuka kunci atau pengiriman ulang**:
wali diminta menghubungi panitia. Koreksi terkontrol merupakan tahap berikutnya.
Admin dapat memeriksa pendaftaran terkirim dari periode diarsipkan juga.

Grant pusat tetap pada `admin_accounts`, dikelola CLI. Staf pada `staff_accounts`
dan `staff_schools`; akun/peserta lama tetap utuh tanpa membangun ulang `users`.
Register mengabaikan role dari klien. Undangan tidak mempromosikan akun wali atau
admin yang sudah ada. **Portal admin, verifikasi, aktivasi undangan dan akses
dokumen staf masih diblokir pada produksi** sampai aturan, kewenangan, privasi,
pengamanan berkas dan operasional dinyatakan siap; adanya MFA tidak membuka gate.

### Tahap 1: akun staf dan akses sekolah

| Peran | Hak yang tersedia |
|---|---|
| Admin pusat | Kelola akun/master, dashboard seluruh sekolah, penugasan verifikator, pemeriksaan peserta dan audit global. Akun pusat tidak dapat dibuat/diubah lewat formulir staf. |
| Admin sekolah | Dashboard/daftar/pemeriksaan peserta terkirim serta penugasan verifikator pada sekolah yang ditugaskan. Tidak boleh mengubah master, mengelola akun atau membaca audit global. |
| Verifikator | Dashboard/daftar/pemeriksaan hanya peserta terkirim yang ditugaskan kepadanya, pada sekolah yang termasuk grant aktifnya. |

Satu staf dapat menangani beberapa sekolah. Pembatasan berlaku pada query
dashboard/filter/pagination, GET detail, POST keputusan/penugasan dan unduh
dokumen, bukan hanya menu. Draf tidak dibuka untuk pemeriksaan. Verifikator
aktif dari sekolah peserta dapat dipilih pada **Penugasan verifikator** di detail
peserta oleh admin pusat/sekolah; konflik versi penugasan ditolak.
Menu ekspor belum tersedia dan tidak ditambahkan sebagai placeholder.

Alur penggunaan:

1. Admin lama: buka **Profil akun → Kirim tautan verifikasi**. Dengan
   `MAIL_TRANSPORT=file`, tautan berada di berkas `.eml` privat terbaru dalam
   `APP_STORAGE/mail/`; ini bukan email yang dikirim ke internet. Buka tautan,
   lalu klik **Verifikasi email**. Jangan menaruh tautan/token pada source code.
2. Opsional: pilih **Atur 2FA** pada pengaturan profil akun. Tambahkan secret secara manual ke aplikasi autentikator TOTP (6 digit,
   30 detik, SHA-1), konfirmasikan password dan kode. Secret pengaturan berlaku
   10 menit. Simpan delapan kode pemulihan yang ditampilkan sekali secara privat.
   Membuka pengaturan tidak membuat secret; setup dapat dibatalkan, dan 2FA baru
   aktif setelah password dan kode berhasil dikonfirmasi.
   Aksi aktivasi/batal diberi jarak terpisah; tombol memenuhi lebar formulir pada
   ponsel, dan tautan kembali berada di luar kartu dengan jarak yang jelas.
3. Buka **Akun & akses**, masukkan nama/email staf tersendiri, pilih admin
   sekolah/verifikator dan minimal satu sekolah, lalu **Kirim undangan**.
   Transport file menyimpan undangan di folder mail privat; transport mail
   membutuhkan MTA/relay. Kegagalan pengiriman menghasilkan kesalahan, bukan sukses.
4. Staf membuka undangan setelah keluar dari akun lain, membuat password sendiri,
   membaca privasi dan mengaktifkan akun. Token berlaku 24 jam, sekali pakai;
   GET tidak mengonsumsi token. Aktivasi membuktikan kepemilikan email, lalu staf
   login tanpa wajib mengatur 2FA sebelum melihat data admin.
5. Jika 2FA diaktifkan, login berikutnya memerlukan kode autentikator atau kode pemulihan
   sekali pakai. Kode TOTP yang telah dipakai tidak dapat diputar ulang.
6. Edit akses/peran atau nonaktifkan staf melalui **Edit akses**. Semua sesi staf
   dicabut dan penugasan pesertanya dilepas; lakukan penugasan ulang bila perlu.
   Keputusan/dokumen peserta lama tidak dihapus. Aktivasi kembali tidak
   mengembalikan sesi atau penugasan otomatis. Penghapusan sekolah yang belum
   dipakai juga mencabut sesi staf terdampak dan membatalkan undangan terkait.

2FA dapat dinonaktifkan melalui pengaturan profil akun dengan password, konfirmasi
dan sesi yang sudah lolos challenge 2FA. Secret/kode pemulihan serta sesi lain
dicabut, perubahan diaudit; login berikutnya tidak meminta kode. Akun yang telah
mengaktifkan 2FA tidak dinonaktifkan otomatis oleh perubahan kebijakan ini.
Jika perangkat diganti/hilang tetapi
kode pemulihan tersedia, login menggunakan satu kode, buka **Ganti autentikator**,
konfirmasikan password/pencabutan, lalu enroll ulang. Secret/kode lama dan sesi
lain dicabut. Jika perangkat dan seluruh kode pemulihan hilang, pemulihan
privileged belum disediakan; jangan menghapus tabel atau menonaktifkan gate.

Tombol **Lewati 2FA untuk pengujian** dihapus karena 2FA kini pilihan akun.
Flag sesi pengujian lama tidak dapat melewati challenge akun yang 2FA-nya aktif.
Verifikasi email staf, izin sekolah/penugasan dan gate produksi tidak berubah.

Secret MFA dienkripsi AES-256-GCM di database; kuncinya `APP_STORAGE/mfa.key`
dibuat privat di luar folder publik. Token undangan/verifikasi dan kode pemulihan
disimpan sebagai hash, tidak dimasukkan audit. **Backup privat harus mencakup
database, dokumen, dan kunci MFA**; jangan mengganti/menghapus kunci saat restore.
Jangan mengunggah storage ke version control. Untuk akun yang mengaktifkan 2FA,
sesi yang belum lolos challenge tidak bisa mengakses dashboard/data/dokumen
walaupun password sudah benar; berlaku untuk staf maupun wali.

Hak **Approver paket aturan sekolah** terpisah dari peran dasar. Staf aktif dengan
hak ini dapat meninjau, menyetujui atau mengembalikan paket pada sekolah yang
ditugaskan, bukan paket yang disusun/diajukannya sendiri. Penerbitan tetap oleh
admin pusat; hak approver belum mencakup hasil seleksi.

### Tahap 2: master operasional dan persetujuan aturan

Tersedia melalui dropdown **Master data → Tahun ajaran / Paket aturan operasional**.
Daftar memakai pencarian, pagination 10 baris, ikon detail/edit/hapus yang sesuai
status, dan toggle aktif/arsip tahun ajaran.

1. **Tahun ajaran:** label dua tahun berurutan, tanggal mulai/akhir, status
   aktif/arsip. Label tahun dari periode lama dimigrasikan tanpa menebak tanggal;
   lengkapi tanggal sebelum dipakai paket. Label yang dipakai periode tidak dapat
   diganti; tanggal/label tahun yang dipakai paket terkunci. Hapus hanya sebelum
   dipakai, dengan konfirmasi. Arsip tahun membatasi pembuatan/persetujuan paket
   baru, tidak menutup periode terbit secara diam-diam.
2. **Rombel dan daya tampung:** nama rombel unik, jumlah kursi per rombel,
   batas kursi dari Juknis, total kapasitas. Total rombel wajib tepat kapasitas.
   Tambah/hapus rombel bekerja dengan JS maupun POST tanpa JS. Tidak ada kapasitas
   atau batas resmi yang diisi otomatis.
3. **Kuota jalur:** kursi bulat untuk tepat semua jalur periode, total sama
   kapasitas. Persentase ringkasan dihitung dari kursi; validasi memakai hitungan
   bilangan bulat, bukan persentase tampilan yang dibulatkan. Baseline negeri
   Permendikdasmen 3/2025: SD domisili ≥70%, afirmasi ≥15%; SMP ≥40%/20%/25%
   untuk domisili/afirmasi/prestasi; SMA ≥30%/30%/30%. Mutasi ≤5%, prestasi tidak
   untuk SD. Ambang ini tidak diterapkan otomatis ke swasta mandiri. Kuota efektif,
   pembulatan dan kursi sisa tetap harus ditentukan dari Juknis yang diperiksa.
4. **Jadwal tujuh tahap:** pendaftaran, verifikasi, perbaikan, seleksi,
   pengumuman, sanggah, daftar ulang, memakai zona waktu periode. Pendaftaran
   mengikuti pembukaan/penutupan periode. Verifikasi boleh overlap pendaftaran
   dan perbaikan; perbaikan dalam rentang verifikasi. Seleksi setelah ketiganya
   selesai; pengumuman → sanggah → daftar ulang berurutan. Rentang masuk tahun
   kalender awal hingga akhir tahun ajaran. Jadwal selain pendaftaran masih
   konfigurasi untuk modul lanjutan, bukan eksekusi seleksi/koreksi otomatis.
5. **Juknis:** nomor, penerbit, versi, tanggal terbit, masa berlaku, tautan
   naskah HTTPS tanpa kredensial, serta rujukan prioritas/tie-break/pembulatan/
   kursi sisa. Masa berlaku wajib mencakup pendaftaran sampai daftar ulang.
   Server tidak mengunduh tautan; penyimpanan salinan PDF Juknis belum tersedia.
   Persetujuan panitia dalam aplikasi bukan pengesahan legalitas naskah.
6. **Paket per sekolah/periode:** snapshot identitas, jalur/dokumen dan konfigurasi
   sumber, versi, alasan perubahan, fingerprint SHA-256, serta riwayat aktor/
   catatan/waktu. Draf → diajukan → disetujui → diterbitkan; approver dapat
   mengembalikan untuk diedit/diajukan lagi. Approver wajib berbeda dari penyusun
   dan pengaju. Konflik versi, sumber berubah dan grant approver dicabut ditolak.
   Paket disetujui dapat diajukan ulang bila perlu approver baru.

**Cara mencoba:** lengkapi tahun ajaran → siapkan periode belum pernah dipakai
(salin periode lama bila perlu) → susun paket dan tinjau ringkasannya → ajukan →
login sebagai staf sekolah dengan grant approver untuk menyetujui/mengembalikan →
admin pusat menerbitkan → aktifkan periode lewat toggle katalog.

Paket pertama mengarsipkan periode hingga diterbitkan. Penerbitan tidak otomatis
mengaktifkan katalog; jadwal, status sekolah dan toggle periode tetap berlaku.
Paket terbit tidak diedit: versi berikutnya memerlukan persetujuan baru, dan hanya
bisa mengganti versi lama jika periode belum pernah dipakai. Setelah ada draf
sekalipun, gunakan periode baru; aturan/snapshot peserta tidak ditimpa. Paket/
riwayatnya tidak dihapus lewat UI dan periode yang memiliki paket tidak bisa
dihapus. Identitas sekolah/periode dibekukan saat paket diajukan/terbit.

Periode pilot lama tanpa paket tetap mengikuti perilaku development sebelumnya;
tidak dianggap memiliki persetujuan operasional. Setelah periode masuk alur paket,
aktivasi/manual SQL/seed katalog tidak melewati pemeriksaan status dan integritas
paket pada daftar, detail, POST pendaftaran, master, dashboard atau CLI list.
Konfigurasi paket terbit ikut `rule_snapshot_json` saat peserta mengirim.
Import atau salin periode tidak dapat membawa klaim persetujuan `operational`;
paket harus diterbitkan ulang melalui alur resmi. **Semua gate produksi tetap ada.**

### CRUD master sekolah dan periode

Pemilihan Sekolah / Periode pendaftaran hanya melalui dropdown Master data di
header; tidak ada tab duplikat di bawah judul. Aksi tabel memakai ikon detail,
edit, hapus, dan salin dengan label aksesibilitas serta tooltip. Toggle biru
berarti aktif, abu-abu berarti arsip; klik menyimpan melalui POST dengan CSRF dan
versi data, serta mempertahankan pencarian/halaman tabel. Penghapusan tetap
memerlukan halaman konfirmasi. Banner “Lingkungan pengembangan” tidak ditampilkan;
penanda periode belum dibuka dan pembatasan produksi tidak diubah.

- Sekolah menyimpan NPSN unik (8 digit), nama, jenjang, mode negeri/swasta, wilayah,
  dan alamat. Data sekolah awal dihubungkan otomatis dari periode yang sudah ada,
  termasuk data lama/arsip; bukan hanya 40 periode aktif. Snapshot sumber resmi
  tidak diubah oleh CRUD.
- Periode memilih sekolah, kode unik, tahun ajaran, jadwal/zona waktu, penyelenggara,
  kontak, rujukan aturan, dan privasi. Editor mendukung tambah/hapus jalur serta
  dokumen dengan kode, nama, deskripsi jalur, dan penandaan wajib/opsional.
  Template negeri/swasta SD/SMP/SMA dapat mengganti isi editor setelah konfirmasi.
  Batas tetap 1–20 jalur dan maksimal 10 dokumen/jalur; kode unik dan aturan jalur
  negeri (tanpa prestasi SD) divalidasi server.
- Periode baru disimpan sebagai **arsip** dan `is_demo=true`. Aktifkan melalui toggle tabel atau detail
  setelah ditinjau. Ini hanya publikasi formulir pengujian, bukan pengesahan
  penerimaan nyata. Akses produksi tetap diblokir.
- Sekolah/periode yang **belum pernah dipakai pendaftaran** boleh diedit.
  Perubahan identitas sekolah memperbarui konfigurasi periode terkait yang belum
  dipakai; versi editor periode ikut dinaikkan. Jenjang/mode sekolah yang sudah
  memiliki periode tidak dapat diganti sebelum periode tersebut dihapus.
- Jika sekolah/periode telah dipakai, edit/hapus ditolak pada server. Penanda
  pernah dipakai dipertahankan meskipun semua draf dibatalkan. Untuk aturan baru,
  gunakan **Salin menjadi periode baru**, tentukan kode baru, kemudian edit hasilnya.
  Pendaftaran/snapshot lama tetap utuh. Hapus sekolah hanya jika tidak memiliki
  periode; hapus periode yang belum dipakai terlebih dahulu.
- Arsip sekolah juga mengarsipkan seluruh periodenya; aktivasi sekolah tidak
  otomatis mengaktifkan periode kembali. Periode tidak dapat diaktifkan untuk
  sekolah diarsipkan. Data peserta tetap terbaca, perubahan/pengiriman diblokir.
- Semua perubahan memakai CSRF, pengecekan admin, optimistic locking, transaksi,
  dan audit. CLI import tetap tidak menimpa kode yang ada. Metadata master sekolah,
  hubungan sekolah-periode, versi, dan penanda penggunaan ditambah tanpa mengubah
  akun, dokumen, atau data pendaftaran lama.

### Ambil lokasi pada profil peserta

Formulir tambah/edit profil menyediakan tombol **Ambil lokasi** untuk mengisi
provinsi, kabupaten/kota, kecamatan, desa/kelurahan, dan kode pos. Fitur memerlukan
persetujuan pengguna, izin lokasi browser, serta HTTPS (atau localhost).
`http://ppdb.test` bukan secure context; gunakan HTTPS Herd untuk GPS.
Lokasi perangkat harus berada di domisili peserta, bukan lokasi wali saat bepergian.
Hasil bukan bukti domisili atau verifikasi kelayakan jalur.

Atur `GEOCODING_URL` pada environment PHP web server ke endpoint reverse-geocoding
**internal/self-hosted yang dikelola sendiri**, bukan layanan pihak ketiga.
Default kosong: aplikasi menjelaskan layanan belum dikonfigurasi dan tidak meminta
GPS. URL hanya ditentukan pengelola, tidak menerima URL dari pengguna, tidak boleh
mengandung kredensial/query/fragment, dan redirect layanan tidak diikuti.
PHP memerlukan `allow_url_fopen`; gunakan HTTPS untuk koneksi selain loopback.
Layanan internal harus menyediakan endpoint/adaptor dengan kontrak:

```http
POST /reverse
Content-Type: application/json

{"latitude":3.5,"longitude":99.1}
```

Respons sukses HTTP 200 (maksimal 32 KiB):

```json
{
  "country_code": "id",
  "province": "Provinsi Uji",
  "city": "Kabupaten Uji",
  "district": "Kecamatan Uji",
  "village": "Desa Uji",
  "postal_code": "12345"
}
```

`country_code` wajib; hanya Indonesia diterima. Wilayah yang tidak ditemukan
dapat bernilai string kosong atau tidak disertakan. Kode pos harus lima digit jika
tersedia. Jika memakai Nominatim self-hosted, sediakan adaptor yang memetakan
hierarki administratif Indonesia ke kontrak ini; respons mentah Nominatim tidak
langsung kompatibel. Jangan menebak desa/kode pos dari level administrasi lain.
Data dan layanan geocoding harus tersedia dahulu agar fitur benar-benar aktif.

Browser mengirim koordinat melalui POST ke aplikasi dan aplikasi meneruskannya
ke layanan internal tanpa identitas peserta. Koordinat tidak disimpan pada profil,
database, atau audit aplikasi; pengelola harus menonaktifkan logging body pada
proxy/layanan internal juga. Maksimal 15 permintaan per akun dalam 15 menit.
Hasil hanya mengganti kolom wilayah yang ditemukan, menandai perubahan belum
tersimpan, dan menampilkan kolom yang masih perlu diisi/diperiksa manual.
Alamat jalan/nomor rumah tidak diganti. Tetap tekan **Simpan profil**; draf
pendaftaran yang sudah dibuat tidak diubah. Fitur diblokir pada produksi seperti
perubahan modul registrasi lainnya.

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
Endpoint `/documents/{id}` memeriksa sesi dan kepemilikan aplikasi; admin pusat
development hanya diizinkan membaca dokumen aktif dari pendaftaran terkirim.
Menebak ID tidak memberikan akses. Maksimal 2 MB/file dan 20 megapiksel/gambar, MIME/ekstensi diperiksa,
token CSRF wajib, dan hash SHA-256 diperiksa saat unduh dan pengiriman.
PDF disajikan sebagai unduhan, bukan HTML/iframe aktif; gambar dapat dipratinjau.
Atur `upload_max_filesize` minimal `2M` dan `post_max_size` minimal `4M`; respons
413/422 ditampilkan jika ukuran melewati batas. Maksimal 30 percobaan upload per akun
dalam 15 menit.

Penggantian/penghapusan dokumen dari checklist mencabut akses ke versi lama, tetapi
salinan privat dan metadata tetap disimpan untuk audit. Tidak ada pembersihan retensi
otomatis pada tahap ini. Tentukan dan implementasikan retensi sebelum data nyata.
Pemindaian malware/antivirus dan enkripsi at-rest belum tersedia. Verifikasi
manual panitia tersedia pada portal admin development, bukan validasi otomatis.

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
Tes admin mencakup grant peran CLI, register tanpa eskalasi peran, larangan
pemeriksaan draf, akses dokumen, keputusan/catatan, konflik versi, riwayat,
status wali dan snapshot tetap utuh, master 40 periode, serta blokir produksi.
Tes master mencakup CRUD, NPSN/kode duplikat, validasi jalur/jadwal, pencarian dan
pagination, konflik versi, propagasi identitas sekolah sebelum dipakai, arsip
sekolah/periode, blokir hapus/edit setelah dipakai (termasuk draf dibatalkan), dan
proteksi akses admin/produksi.
Tes staf mencakup undangan valid/batal/kedaluwarsa/sekali pakai, kolisi email
tanpa promosi wali, email verification/CSRF, MFA/replay/rate limit, hash recovery,
enkripsi secret, rotasi autentikator, penugasan beberapa sekolah, akses GET/POST/
dokumen lintas sekolah, pembatasan verifikator, konflik versi dan pencabutan sesi.
Tes operasional mencakup CRUD/lock tahun, kapasitas rombel dan kuota tepat,
ambang negeri dengan kursi bulat, swasta tanpa ambang negeri, overlap/urutan tahap,
validitas/masa berlaku Juknis, sumber berubah, CSRF/versi, isolasi approver,
pengembalian/persetujuan/penerbitan, pencabutan hak, versi berikutnya, snapshot
kiriman, integritas, gate katalog/CLI/produksi dan rombel tanpa JavaScript.

## Berikutnya

### Roadmap admin-first

**A-01 dan A-02 tersedia untuk pengujian development**: undangan staf, grant sekolah,
2FA/email verification, akun/sesi, tahun ajaran dan paket operasional dengan
kapasitas/kuota/jadwal/Juknis/persetujuan. Penugasan verifikator
dasar dari A-03 juga tersedia; pengelolaan antrean lengkap belum. Tahap lain tetap
backlog, bukan fitur aktif. Rincian scope, prasyarat, kriteria penerimaan, rancangan
menu dan keputusan kebijakan ada di [PRD bagian 15.1](prd.md#151-roadmap-lanjutan-admin-terlebih-dahulu).

| Prioritas | Improvement | Hasil yang dituju |
|---|---|---|
| 1 / A-01 | Akun, peran & akses sekolah (tersedia pada development) | Undangan staf, penugasan sekolah, izin aksi, pencabutan akses/sesi, 2FA opsional dan verifikasi email. Grant approver aturan aktif. |
| 2 / A-02 | Master operasional & aturan (tersedia pada development) | Tahun ajaran, rombel, daya tampung, kuota jalur, jadwal tiap tahap, metadata/tautan Juknis, versi dan persetujuan paket aturan. |
| 3 / A-03 | Pendaftar & antrean | Tabel/filter/sort, penugasan verifikator, antrean kerja dan tinjauan potensi duplikasi. |
| 4 / A-04 | Verifikasi rinci & koreksi | Checklist per berkas/kriteria, permintaan perbaikan terbatas, tenggat, revisi dan kirim ulang tanpa menimpa snapshot awal. |
| 5 / A-05 | Seleksi & simulasi | Kelayakan, skor/prioritas/tie-break sesuai aturan, kuota dan cadangan; hasil dapat direproduksi. |
| 6 / A-06 | Persetujuan & hasil | Review dua pihak, publikasi terjadwal, hasil personal dan koreksi hasil berversi. |
| 7 / A-07 | Sanggah & pengaduan | Tiket, bukti privat, penugasan, tanggapan, tenggat dan eskalasi. |
| 8 / A-08 | Daftar ulang & kursi | Konfirmasi, dokumen akhir, pengunduran diri/tenggat dan penawaran kursi cadangan tanpa alokasi ganda. |
| 9 / A-09 | Laporan & ekspor | Rekap per sekolah/jalur/tahap, CSV aman dan laporan cetak, akses terbatas dan audit ekspor. |
| 10 / A-10 | Informasi & komunikasi | Konten publik disetujui, FAQ, pusat notifikasi, template dan antrean email dengan retry terkontrol. |
| 11 / A-11 | Operasional & gate produksi | Audit berfilter, monitoring, backup/restore, retensi, pemindaian unggahan dan uji beban/akses. |
| 12 / A-12 | Perluasan opsional | Pilihan lintas sekolah, geodata/jarak, swasta/gelombang, tes/beasiswa/pembayaran yang sah dan integrasi resmi berizin. |

**Berikutnya A-03: pendaftar dan antrean kerja, lalu A-04 koreksi terkontrol.** Pembagian
akses A-01 sudah mengikuti keputusan: pusat mengelola akun/master, admin sekolah
menugaskan/memeriksa di sekolahnya, verifikator hanya peserta yang ditugaskan;
satu staf boleh beberapa sekolah. Otorisasi ekspor dan hasil harus memakai
scope ini ketika modulnya dibuat. Jangan langsung membangun ranking sebelum
aturan dan data verifikasi siap.

Setelah fondasi akses dan master operasional selesai, lanjut
**A-03 antrean + A-04 koreksi**. Laporan verifikasi, informasi publik dan
operasional dapat disiapkan paralel sesuai prasyarat; A-11 dimulai sejak fondasi,
bukan baru saat akhir. Target awal tetap SMP negeri Serdang Bedagai.

Admin-first tetap memerlukan alur wali minimum untuk koreksi, notifikasi in-app,
hasil, sanggah dan daftar ulang. Status verifikasi **Valid bukan diterima**;
status kiriman/revisi, verifikasi, seleksi dan daftar ulang harus terpisah.
Portal informasi publik lengkap belum tersedia; katalog sekarang berada di area
wali yang sudah login. Menu baru hanya ditampilkan ketika alurnya berfungsi,
bukan sebagai placeholder.

Sebelum implementasi aturan, pemilik kebijakan perlu menetapkan Juknis/tahun
ajaran, pejabat pengesah, kapasitas/kuota efektif, pembulatan/tie-break/kursi sisa,
jumlah pilihan sekolah dan tenggat. Tidak ada persentase, rumus jarak atau
aturan swasta yang otomatis dianggap sah. Geocoding/peta internal masih belum
tersedia; GPS bukan bukti domisili. Teknologi pengembangan tetap PHP/JS/HTML/CSS
dan SQLite; kelayakan database produksi ditentukan lewat uji beban.

Blokir produksi tidak dihapus oleh roadmap atau toggle katalog. Rilis nyata
memerlukan persetujuan aturan, verifikasi email/MFA, pembatasan akses, privasi/
retensi, backup yang diuji, pengamanan dokumen dan kesiapan operasional.
