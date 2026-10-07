# PRD — Platform Penerimaan Murid Baru (PPDB/SPMB)

**Status:** Draft untuk validasi produk dan kebijakan  
**Tanggal:** 6 Oktober 2026  
**Nama kerja:** SPMB Dinamis  
**Cakupan awal:** Web responsif untuk sekolah/penyelenggara SD, SMP, SMA di Indonesia

## 1. Ringkasan

Platform ini membantu sekolah mengelola penerimaan murid baru dari publikasi informasi sampai daftar ulang. Satu produk melayani sekolah negeri dan swasta melalui profil penyelenggaraan yang berbeda. Antarmuka menyesuaikan perangkat: navigasi bawah (bottom navigation) dan formulir bertahap pada ponsel; navigasi samping/atas, tabel, dan panel kerja pada desktop.

Untuk sekolah negeri, sistem menyediakan **mode kepatuhan SPMB** yang menjalankan aturan nasional dan Juknis pemerintah daerah berdasarkan wilayah, jenjang, serta tahun ajaran. Untuk sekolah swasta, sistem menyediakan **mode penerimaan mandiri** dengan formulir, seleksi, biaya, jadwal, dan kuota yang dapat dikonfigurasi; sekolah dapat memilih mengikuti sebagian atau seluruh jalur SPMB bila kebijakan/kerja sama setempat mengharuskannya. Sistem tidak menganggap seluruh sekolah swasta otomatis tunduk pada kuota jalur negeri yang sama: cakupan hukum dan pembiayaan perlu ditentukan dari regulasi/Juknis yang berlaku.

> **Catatan kepatuhan:** Konfigurasi SPMB wajib ditinjau terhadap Permendikdasmen No. 3 Tahun 2025 dan Juknis Pemda yang berlaku untuk tahun ajaran terkait. Dokumen ini adalah spesifikasi produk, bukan pendapat hukum. Jangan mengaktifkan seleksi publik sebelum pejabat berwenang menyetujui paket aturan dan publikasinya.

## 2. Masalah dan peluang

- Pendaftaran sering tersebar di formulir, pesan, kertas, dan spreadsheet sehingga status berkas serta keputusan sulit dilacak.
- Orang tua membutuhkan pengalaman yang jelas di ponsel, termasuk untuk koneksi terbatas dan pendampingan operator.
- Panitia memerlukan pembagian tugas, pemeriksaan berkas, kapasitas rombongan belajar (rombel), seleksi, pengumuman, daftar ulang, serta audit keputusan.
- Perubahan Juknis per daerah/tahun ajaran membuat aturan yang ditanam permanen dalam kode menjadi cepat usang.
- Sekolah swasta perlu alur penerimaan yang lebih fleksibel, termasuk gelombang, tes, wawancara, beasiswa, dan pembayaran, tanpa mencampur aturan itu dengan mode negeri.

## 3. Tujuan dan indikator keberhasilan

### Tujuan
1. Memungkinkan calon murid/ortu menyelesaikan pendaftaran melalui ponsel atau desktop.
2. Mengurangi berkas tidak lengkap dan pertanyaan status melalui checklist, notifikasi, serta pelacakan mandiri.
3. Memberi panitia proses seleksi yang dapat ditelusuri, terkendali berdasarkan kapasitas, dan dapat diaudit.
4. Mengonfigurasi penerimaan per sekolah, jenjang, tahun ajaran, wilayah, mode aturan, jalur, dan gelombang tanpa perubahan perangkat lunak.
5. Mencegah konfigurasi mode negeri terbit jika bertentangan dengan parameter nasional atau Juknis daerah yang telah dimasukkan.

### Indikator (diukur per periode penerimaan)
- Persentase pendaftar yang menyelesaikan formulir dan unggah dokumen.
- Persentase berkas lengkap pada pengiriman pertama.
- Median waktu pemeriksaan berkas dan median waktu penyelesaian pendaftaran.
- Tingkat keberhasilan daftar ulang dan jumlah kursi kosong setelah batas daftar ulang.
- Jumlah koreksi keputusan, keluhan, serta insiden akses data.
- Persentase seluruh perubahan keputusan/kuota/aturan yang memiliki pelaku, waktu, alasan, dan bukti audit.
- Kinerja aksesibilitas dan waktu muat halaman penting pada perangkat seluler.

Target numerik ditetapkan setelah pilot memperoleh baseline.

## 4. Pengguna dan hak akses

| Peran | Kebutuhan utama |
|---|---|
| Calon murid/wali | Memahami persyaratan, mendaftar, mengirim bukti, melihat status/hasil, mengajukan keberatan, dan daftar ulang. |
| Petugas/operator sekolah | Membantu pembuatan akun/pendaftaran, memeriksa berkas, mencatat komunikasi, dan mengelola antrean. |
| Verifikator | Memvalidasi dokumen dan kriteria jalur; tidak mengubah aturan seleksi. |
| Tim seleksi | Menilai tes/wawancara/prestasi sesuai rubrik yang telah dipublikasikan. |
| Ketua panitia/kepala sekolah | Menyetujui kapasitas, paket aturan, hasil, pengumuman, dan daftar ulang. |
| Admin dinas (opsional) | Mengelola Juknis/area/kuota lintas sekolah sesuai kewenangan; memantau laporan. |
| Admin platform | Mengelola tenant, keamanan, dukungan, dan audit teknis; tidak memutus penerimaan. |

Gunakan prinsip least privilege, pemisahan tugas, MFA untuk peran berisiko, serta persetujuan dua pihak untuk publikasi hasil dan perubahan kuota setelah pendaftaran dibuka.

## 5. Cakupan produk

### Implementasi pilot saat ini (6 Oktober 2026)

Admin pusat lintas 40 SMP negeri Serdang Bedagai tersedia pada development:
dashboard, daftar/pencarian/filter/paginasi pendaftaran terkirim, pemeriksaan data
dan dokumen, hasil verifikasi Valid / Perlu perbaikan / Tidak valid dengan catatan
wajib, riwayat keputusan, CRUD master sekolah/periode melalui tabel berpagination,
editor jalur dan dokumen, pengelolaan staf dan audit. A-01 tersedia pada development:
undangan admin sekolah/verifikator, beberapa sekolah per staf, MFA opsional melalui pengaturan profil akun,
verifikasi email, aktif/nonaktif/perubahan peran dengan pencabutan sesi.
Admin sekolah boleh menugaskan dan memeriksa peserta terkirim pada sekolahnya,
tidak mengelola akun/master/audit global. Verifikator hanya memeriksa peserta yang
ditugaskan kepadanya; pembatasan berlaku pada dashboard/list/filter/detail/POST
dan dokumen. A-03 tersedia: tabel/filter/sort/pagination, ringkasan antrean,
penugasan/pengalihan/pelepasan ber-versi dan riwayat, indikator tenggat dari
snapshot, serta tinjauan potensi duplikasi dalam cakupan akses. Verifikator boleh
mengambil tugas kosong pada sekolahnya melalui ringkasan minimum tanpa identitas
atau dokumen; akses detail terbuka hanya setelah transaksi berhasil.
Akun uji dibuat CLI, bukan register publik. Hasil terlihat oleh wali tetapi bukan
keputusan penerimaan; data terkirim tetap terkunci. A-02 tersedia pada development:
tahun ajaran, rombel/kapasitas, kuota kursi, tujuh jadwal tahap, metadata/tautan
Juknis dan paket berversi dengan persetujuan approver sekolah yang berbeda dari
penyusun/pengaju. A-04 tersedia pada development: checklist rinci, koreksi terbatas
dalam tahap Perbaikan snapshot, kirim ulang revisi tanpa menimpa kiriman awal,
arsip berkas/keputusan dan notifikasi in-app. Seleksi/persetujuan hasil belum
tersedia. Portal admin diblokir pada produksi.
Dropdown master memisahkan Sekolah, Periode pendaftaran, Tahun ajaran dan Paket
aturan operasional. Edit/hapus sekolah/periode hanya
sebelum data pernah dipakai pendaftaran; sesudah itu hanya arsip atau salin menjadi
periode baru. Draf yang dibatalkan tidak membuka kembali hak edit/hapus master.
Sekolah dengan periode harus menghapus periode yang belum dipakai terlebih dahulu
sebelum sekolah dapat dihapus. Arsip sekolah mengarsipkan seluruh periodenya,
tanpa menghapus data peserta. Periode baru selalu arsip dan untuk pengujian.
Pemilihan master hanya melalui dropdown header, tanpa tab duplikat. Tabel memakai
ikon aksi berlabel aksesibilitas dan toggle aktif/arsip yang menyimpan melalui POST.
Penghapusan tetap melalui konfirmasi. Penghilangan banner lingkungan pengembangan
tidak menghapus penanda periode uji atau membuka pembatasan produksi.

### MVP
- Multi-tenant: yayasan/dinas, sekolah, jenjang, kampus/lokasi, tahun ajaran, gelombang.
- Halaman publik informasi penerimaan, jadwal, daya tampung, jalur, syarat, FAQ, kanal pengaduan.
- Akun wali dan calon murid; formulir tersimpan sebagai draf; unggah dan pratinjau berkas.
- Pendaftaran satu/multiple pilihan sekolah sesuai konfigurasi; nomor pendaftaran dan tanda terima.
- Verifikasi berkas dengan status, catatan yang aman ditampilkan, permintaan perbaikan, dan tenggat.
- Papan kerja panitia, antrean, filter, penugasan, dan ekspor terbatas.
- Mesin aturan seleksi berversi: kelayakan, kuota, pemeringkatan, tie-break, dan daftar cadangan.
- Pengumuman privat per pendaftar, masa sanggah/keberatan yang bisa dikonfigurasi, daftar ulang dan konfirmasi kursi.
- Notifikasi email/SMS/WhatsApp melalui integrasi berizin; pusat notifikasi dalam aplikasi.
- Audit log, laporan operasional, backup, pengaturan retensi dan ekspor data.
- UI responsif, aksesibilitas dasar, autosave, serta kanal bantuan.

### Setelah MVP
- Integrasi resmi Dapodik/EMIS, Dukcapil, DTKS/Data sosial, atau sistem daerah bila API dan otorisasi tersedia.
- Verifikasi otomatis dokumen/identitas dengan persetujuan dan human review.
- Beasiswa dan simulasi pembiayaan sekolah swasta; tes daring; penjadwalan wawancara.
- Peta wilayah penerimaan dan estimasi jarak bila metode itu disahkan dalam Juknis.
- Aplikasi/PWA offline-assisted untuk petugas dengan sinkronisasi terkendali.
- Analitik lintas tahun dan antisipasi kapasitas.

### Di luar cakupan awal
- Menetapkan kebijakan kuota atau wilayah bagi pemerintah.
- Mengambil keputusan penerimaan otomatis tanpa aturan yang disetujui dan dapat dijelaskan.
- Menggantikan sistem data nasional/daerah atau menjanjikan integrasi yang belum disahkan.
- Seleksi berbasis data sensitif yang tidak memiliki dasar dan kebutuhan yang sah.

## 6. Model aturan dan konfigurasi

Setiap penerimaan adalah **paket aturan** dengan status: Draft → Ditinjau → Disetujui → Terjadwal → Aktif → Ditutup/Diarsipkan. Paket berisi:

- Identitas penyelenggara, sekolah, jenjang, tahun ajaran, wilayah kewenangan, dan mode (SPMB negeri / mandiri swasta / konfigurasi khusus yang disetujui).
- Salinan/rujukan regulasi nasional dan Juknis lokal: nomor, tanggal, penerbit, tautan, tanggal berlaku, versi, dan dokumen bukti.
- Jadwal tiap tahap dan zona waktu; batas perbaikan berkas; periode sanggah; daftar ulang.
- Daya tampung per sekolah/program/rombel, kapasitas rombel dan batas yang berlaku.
- Jalur, subjalur, persentase/kuota, kriteria, urutan prioritas, dokumen, rumus skor, tie-break, aturan kursi sisa, dan pilihan sekolah.
- Metode verifikasi, tahap manual/otomatis, siapa yang berwenang, serta alasan penolakan yang dapat ditampilkan.
- Pratinjau simulasi: total kursi, pembulatan kuota, konflik aturan, pendaftar yang terdampak, dan hasil contoh.

**Pengamanan perubahan:** aturan yang sudah terbit tidak diedit langsung. Perubahan membuat versi baru, mencatat alasan, memerlukan persetujuan berwenang, menampilkan dampak, dan menghasilkan pemberitahuan publik bila memengaruhi pendaftar. Sistem menyimpan versi aturan yang digunakan untuk setiap keputusan.

### Aturan nasional untuk mode negeri (baseline dari Permendikdasmen No. 3 Tahun 2025)

Berikut parameter dasar; Juknis Pemda dapat mengatur pelaksanaan dan distribusi rinci dalam koridor yang berlaku. Nilai efektif tidak boleh diasumsikan sama antarwilayah/tahun ajaran.

| Jenjang | Domisili | Afirmasi | Prestasi | Mutasi |
|---|---:|---:|---:|---:|
| SD | minimal 70% | minimal 15% | Jalur prestasi tidak berlaku untuk kelas 1 SD | maksimal 5% |
| SMP | minimal 40% | minimal 20% | minimal 25% | maksimal 5% |
| SMA | minimal 30% | minimal 30% | minimal 30% | maksimal 5% |

Pemerintah daerah menetapkan persentase efektif hingga total daya tampung; sisa kuota mutasi dapat dialokasikan sesuai ketentuan. Sistem harus menolak total kuota lebih dari 100%, melaporkan kekurangan dari kapasitas, dan memvalidasi ambang minimum/maksimum sesuai jenjang. Jangan menerapkan angka ini ke mode sekolah swasta mandiri secara otomatis.

### Persyaratan calon murid
- Modelkan syarat umum dan khusus sebagai aturan terstruktur per jenjang/jalur/tahun, bukan teks bebas saja.
- Pemeriksaan dasar yang lazim meliputi kelulusan/tingkat kelas asal dan batas usia sesuai ketentuan. Untuk SD, usia paling rendah pada umumnya 6 tahun per 1 Juli tahun berjalan; usia 7 tahun diprioritaskan, dan pengecualian usia harus mengikuti syarat regulasi (misalnya rekomendasi profesional untuk calon tertentu). SMP dan SMA memiliki batas usia/kelulusan yang perlu dimuat persis dari aturan berlaku.
- Pengecualian untuk penyandang disabilitas atau kondisi lain harus mengikuti pasal yang berlaku dan tidak boleh dikodekan sebagai penolakan otomatis.
- Persyaratan domisili, afirmasi, prestasi, dan mutasi berasal dari aturan nasional serta Juknis yang berlaku. Minta bukti minimum yang ditentukan, dukung verifikasi manual, dan sediakan koreksi data.
- Daftar final syarat usia, bukti, masa domisili, dan metode seleksi harus diverifikasi terhadap naskah resmi/Juknis daerah sebelum paket aturan dipublikasikan.

### Mode swasta mandiri
Sekolah dapat membuat gelombang dan jalur mandiri, tes/observasi yang sesuai jenjang, wawancara, kriteria akademik/nonakademik, beasiswa, biaya, dan batas penerimaan. Wajib menyampaikan syarat, proses, tenggat, biaya, mekanisme keberatan, kebijakan data, dan hasil dengan jelas. Pengaturan tetap tunduk pada aturan perlindungan anak, data pribadi, pendidikan, nondiskriminasi, serta kewajiban lokal yang relevan.

## 7. Alur utama calon murid/wali

1. **Temukan informasi:** pilih tahun ajaran, sekolah, jenjang, lihat jadwal, daya tampung, jalur, syarat, dokumen, biaya (bila ada), kebijakan privasi, dan FAQ.
2. **Buat akun:** nomor ponsel/email wali, OTP, persetujuan yang sesuai, lalu tambah profil calon murid.
3. **Pilih jalur/gelombang:** sistem menjelaskan kelayakan awal dan dokumen; kelayakan awal bukan jaminan diterima.
4. **Isi formulir:** identitas, data sekolah asal, wali, domisili, pilihan; autosave dan indikator langkah.
5. **Unggah dokumen:** panduan format/ukuran, kamera ponsel, pratinjau, akses bantuan tatap muka melalui operator.
6. **Tinjau dan kirim:** ringkasan data, deklarasi kebenaran, persetujuan, tanda terima bertimestamp dan nomor pendaftaran.
7. **Verifikasi:** status “menunggu”, “perlu perbaikan”, “terverifikasi”, atau “tidak memenuhi syarat” dengan alasan dan langkah lanjut. Perbaikan hanya dalam periode yang diizinkan.
8. **Seleksi:** sesuai aturan terpublikasi. Status dapat “diproses”; jangan tampilkan peringkat/posisi jika bisa mengungkap data orang lain atau menyesatkan.
9. **Hasil dan sanggah:** hasil personal, alasan/status, tenggat dan formulir keberatan; nomor tiket dan jejak tanggapan.
10. **Daftar ulang:** konfirmasi, lengkapi dokumen, pembayaran hanya bila sah/berlaku untuk jalur tersebut, bukti transaksi resmi; lepaskan kursi setelah tenggat sesuai aturan.
11. **Selesai:** tanda terima daftar ulang atau informasi daftar cadangan/tidak diterima dan jalur informasi selanjutnya.

## 8. Alur panitia

1. Buat periode, sekolah/jenjang, kapasitas dan rombel.
2. Pilih template aturan berdasarkan mode; masukkan Juknis, wilayah, kuota, jadwal, syarat, skor dan tie-break.
3. Jalankan pemeriksaan konflik serta simulasi; tinjau laporan dan contoh keputusan.
4. Dapatkan persetujuan pejabat yang berwenang; terbitkan informasi publik dan buka pendaftaran.
5. Pantau kapasitas dan antrean; tetapkan petugas verifikasi; minta perbaikan berkas dengan templat pesan.
6. Kunci data tahap sesuai jadwal; lakukan seleksi menggunakan snapshot aturan dan data; tinjau daftar hasil oleh dua pihak.
7. Terbitkan hasil; tangani keberatan melalui petugas yang berbeda bila memungkinkan.
8. Pantau daftar ulang, daftar cadangan, kursi kosong dan penyaluran sisa kuota sesuai aturan.
9. Tutup periode, ekspor laporan, arsipkan paket aturan, dan jalankan retensi/penghapusan sesuai kebijakan.

## 9. Navigasi dan pengalaman lintas perangkat

### Ponsel
- Bottom bar pendaftar: **Beranda**, **Daftar**, **Status**, **Bantuan**. Profil dan pengaturan di menu akun.
- Alur formulir satu kolom, satu kelompok pertanyaan per langkah; tombol aksi melekat di bawah dengan ruang aman; indikator progres dan simpan otomatis.
- Panitia: bottom bar **Ringkasan**, **Pendaftar**, **Tugas**, **Lainnya**; kartu ringkas menggantikan tabel lebar.
- Unggah dokumen dari kamera/galeri, kemampuan melanjutkan unggah gagal, validasi jelas, tanpa interaksi hover.

### Desktop
- Pendaftar: navigasi utama di header/samping; formulir multi-kolom yang tetap terkelompok dan memiliki ringkasan.
- Panitia: sidebar modul, tabel dengan pencarian/filter/sort/paginasi, detail pendaftar di panel, pintasan keyboard yang tidak wajib.

### Prinsip umum
- Mulai dari layar terkecil; WCAG 2.2 AA sebagai target desain; kontras, fokus keyboard, label eksplisit, pesan kesalahan terkait kolom, ukuran target sentuh memadai.
- Bahasa Indonesia sederhana; format tanggal, nomor, dan alamat Indonesia; konten dapat diterjemahkan oleh penyelenggara.
- Status tidak hanya dibedakan dengan warna; loading, kosong, offline, gagal unggah, sesi habis, dan koneksi putus memiliki penanganan.
- Performa jaringan lemah: formulir ringan, autosave idempoten, kompresi gambar di sisi klien bila aman, retry terkontrol; indikator kapan data benar-benar terkirim.

## 10. Kebutuhan fungsional

| ID | Kebutuhan |
|---|---|
| F-01 | Sistem memisahkan data tiap yayasan/dinas/sekolah dan memetakan akses per peran. |
| F-02 | Publik dapat mencari dan membandingkan sekolah serta membaca paket penerimaan tanpa akun. |
| F-03 | Pendaftar dapat membuat draf, melanjutkan lintas perangkat, mengirim, memperbaiki, dan melacak status. |
| F-04 | Formulir/dokumen ditentukan oleh jalur, jenjang, kebijakan, dan versi periode. |
| F-05 | Petugas dapat memeriksa/menugaskan berkas dan mencatat keputusan beserta bukti/alasan. |
| F-06 | Mesin seleksi dapat menjalankan kelayakan, kuota, skor, tie-break dan daftar cadangan dari aturan yang disetujui. |
| F-07 | Sebelum aktivasi, sistem memvalidasi kuota, kapasitas, tanggal, konflik syarat dan aturan lokal wajib. |
| F-08 | Hasil tidak dapat dipublikasikan tanpa persetujuan sesuai kebijakan; sistem mencatat snapshot dan hash hasil. |
| F-09 | Pendaftar dapat mengajukan sanggah/pengaduan, melacak tiket, dan menerima tanggapan. |
| F-10 | Daftar ulang mengunci kursi/berakhir pada tenggat; alokasi kursi kosong tercatat dan mengikuti aturan. |
| F-11 | Notifikasi hanya dikirim berdasarkan preferensi dan dasar yang sah; status pengiriman tersimpan. |
| F-12 | Laporan menampilkan kapasitas, pendaftar per jalur, kelengkapan, seleksi, daftar ulang, pengaduan, dan audit. |
| F-13 | Ekspor memerlukan hak akses, tujuan dan jejak; data sensitif diminimalkan/masking. |
| F-14 | Operator dapat memasukkan pendaftar luring atas nama wali dengan pencatatan sumber, persetujuan dan petugas. |
| F-15 | Semua teks persyaratan, biaya, jadwal, hasil dan pemberitahuan memiliki pratinjau publik sebelum tayang. |

## 11. Data, privasi, dan keamanan

- Kumpulkan hanya data yang diperlukan untuk penerimaan; pisahkan identitas, dokumen, hasil seleksi, data kesehatan/disabilitas, dan log akses.
- Tetapkan dasar pemrosesan, pemberitahuan privasi, persetujuan wali bila diperlukan, tujuan, retensi, hak subjek data, pengelola/pemroses, dan prosedur insiden sesuai UU PDP dan aturan sektoral.
- Enkripsi saat transit dan tersimpan; kontrol akses granular; MFA untuk staf; proteksi brute force; URL unduhan berumur pendek; pemindaian berkas; backup terenkripsi dan uji pemulihan.
- Catat akses dokumen sensitif, ekspor, perubahan data/aturan, keputusan dan publikasi. Audit log tidak dapat diedit oleh pengguna biasa.
- Redaksi/masking data pada laporan publik; tampilkan hanya statistik agregat dengan ambang kecil untuk mencegah identifikasi.
- Sediakan retensi per kategori dan penghapusan/anonimisasi setelah tujuan dan masa wajib arsip berakhir. Jadwal retensi harus disahkan pemilik data.
- Larang penggunaan data pendaftar untuk iklan, pemeringkatan komersial, atau pelatihan model tanpa dasar dan persetujuan yang sah.

## 12. Kebutuhan nonfungsional

- Responsif untuk ponsel umum, tablet, dan desktop; dukungan browser modern serta progressive enhancement.
- Sasaran ketersediaan dan kapasitas disepakati per musim; mampu menangani lonjakan menjelang tenggat dengan antrean notifikasi dan perlindungan beban.
- RPO/RTO ditetapkan bersama penyelenggara; pemulihan bencana terdokumentasi.
- Semua waktu disimpan konsisten dan ditampilkan sesuai zona waktu penyelenggara; perubahan jadwal tercatat.
- API terversi, idempotensi pengiriman, rekonsiliasi status unggah/notifikasi, serta observabilitas tanpa membocorkan data pribadi.
- Keputusan seleksi dapat direproduksi dari versi aturan, input, bobot, pembulatan, tie-break, dan waktu proses.

## 13. Entitas data inti

`Organization`, `School`, `Campus`, `AcademicYear`, `AdmissionPeriod`, `RulePackVersion`, `SeatPlan`, `Pathway`, `EligibilityRule`, `Applicant`, `Guardian`, `Application`, `Choice`, `Document`, `VerificationTask`, `Assessment`, `SelectionRun`, `Decision`, `Appeal`, `ReEnrollment`, `Notification`, `AuditEvent`.

Setiap catatan penerimaan merujuk pada tenant, periode, versi aturan, pemilik data, status, waktu pembuatan/perubahan, dan jejak audit yang relevan.

## 14. Risiko dan mitigasi

| Risiko | Mitigasi |
|---|---|
| Juknis berubah setelah sistem dikonfigurasi | Paket aturan berversi, pemantauan regulasi oleh pemilik kebijakan, validasi ulang dan pemberitahuan dampak. |
| Aturan nasional dan lokal bertentangan/ambigu | Blok publikasi; tampilkan konflik dan rujukan; minta keputusan pejabat berwenang. |
| Beban tinggi mendekati penutupan | Uji beban sebagai kriteria rilis, antrean, autosave, halaman status ringan dan perpanjangan hanya oleh otoritas. |
| Dokumen atau data anak bocor | Minimasi, enkripsi, MFA, audit, URL singkat, retensi dan respons insiden. |
| Algoritma menguntungkan kelompok tertentu/tie-break tak transparan | Publikasikan kriteria, simulasi, audit bias yang relevan, human review, mekanisme keberatan. |
| Kesenjangan perangkat/koneksi | Desain ringan, operator luring, bantuan tatap muka, kanal status dan dokumentasi yang mudah dicetak. |
| Konflik kepentingan/ubah hasil tanpa jejak | Pemisahan tugas, persetujuan dua pihak, log tak dapat diubah, alasan wajib, rekonsiliasi hasil. |
| Data integrasi tidak tersedia/izin belum ada | Integrasi opsional; jangan mengklaim verifikasi otomatis; sediakan prosedur manual yang tercatat. |

## 15. Rencana rilis

1. **Fondasi:** tenancy, sekolah, tahun ajaran, peran, desain responsif dan paket aturan.
2. **Pilot penerimaan:** portal publik, akun, formulir, dokumen, verifikasi, notifikasi dasar, laporan dan audit.
3. **Seleksi dan hasil:** simulasi, seleksi berversi, persetujuan dua pihak, hasil personal, keberatan, daftar ulang.
4. **Perluasan:** sekolah swasta mandiri, multi-gelombang, beasiswa/pembayaran, integrasi yang sudah memperoleh akses resmi.

Pilot sebaiknya mencakup sekurangnya satu sekolah negeri dan satu swasta pada satu jenjang, lalu diperluas setelah evaluasi aksesibilitas, akurasi aturan, kapasitas, dukungan operator, dan pengelolaan data.

### 15.1 Roadmap lanjutan: admin terlebih dahulu

**Status A-01, A-02 dan A-03: tersedia pada development; tahap lain masih rencana**.
Verifikasi email wajib untuk staf (undangan
atau token admin lama), opsional untuk wali pada pilot. MFA TOTP pilihan staf maupun wali, nonaktif secara default,
kode pemulihan sekali pakai dan rotasi autentikator tersedia. Persetujuan
aturan sudah tersedia; persetujuan hasil, ekspor dan pemulihan privileged bila perangkat/kode hilang belum
tersedia. Prioritas kerja pertama tetap SMP negeri
Kabupaten Serdang Bedagai; perluasan swasta dilakukan setelah alur inti stabil.
Admin pusat yang sudah ada dipertahankan. Data akun, pendaftaran, dokumen, snapshot,
dan riwayat lama tidak boleh hilang saat migrasi.

Admin-first berarti konfigurasi, otorisasi, alur keputusan, dan audit dibangun
lebih dahulu. Bukan berarti seluruh proses bisa selesai tanpa sisi wali:
perbaikan berkas, hasil personal, sanggah, dan daftar ulang memerlukan pasangan
alur wali minimum pada tahap yang sama. Jangan membuat menu kosong atau tombol
yang seolah sudah menjalankan proses; modul muncul setelah alur lengkapnya siap.

| Urutan / ID | Modul admin dan peningkatan | Prasyarat | Kriteria selesai minimum |
|---|---|---|---|
| 1 / A-01 | **Akun, peran, dan akses sekolah (development):** undangan staf, aktivasi/nonaktif, penugasan satu/beberapa sekolah, hak per aksi, MFA staf dan verifikasi email. | Pemetaan kewenangan disepakati: pusat mengelola akun/master, admin sekolah menugaskan/memeriksa, verifikator hanya peserta yang ditugaskan. | Akses daftar/detail/dokumen/POST dibatasi server; staf sekolah A ditolak mengakses sekolah B meskipun mengganti URL/ID. Pencabutan akses membatalkan sesi dan melepas tugas; seluruh grant diaudit. Scope yang sama wajib digunakan saat ekspor tersedia. |
| 2 / A-02 | **Master operasional (development):** tahun ajaran, rombel, kapasitas sekolah, kuota tiap jalur, tujuh tahap jadwal, metadata/tautan Juknis dan paket aturan berversi. | A-01; naskah Juknis dan pejabat harus ditentukan pengelola. | Total kursi jalur/rombel tepat kapasitas; ambang negeri tidak diterapkan otomatis ke swasta. Jadwal konflik ditolak. Approver sekolah berbeda dari penyusun/pengaju; aturan terbit tidak ditimpa. |
| 3 / A-03 | **Manajemen pendaftar dan antrean (development):** tabel pencarian/filter/sort/pagination, penugasan/ambil tugas kosong, antrean belum ditugaskan/ditangani/terlambat, potensi duplikasi dan ringkasan kerja. | A-01; A-02 untuk tenggat yang diketahui. | Penugasan/pengambilalihan tercatat; konflik dua petugas ditolak. Potensi duplikasi hanya dalam scope pemeriksaan, ditinjau manusia, tanpa hapus/tolak otomatis. Draf tidak dibuka staf. |
| 4 / A-04 | **Verifikasi rinci dan koreksi terkontrol (tersedia development):** checklist per dokumen/kriteria jalur, alasan, permintaan perbaikan kolom/berkas tertentu, tenggat, kirim ulang dan pemeriksaan ulang. | A-02 untuk jadwal/aturan; A-03 untuk penugasan. | Snapshot kiriman pertama tetap utuh; koreksi menjadi revisi baru. Hanya bagian yang diminta dapat diperbaiki dalam tenggat; versi dokumen dan keputusan sebelumnya dapat ditelusuri. Wali dapat memperbaiki/kirim ulang; status dan notifikasi in-app konsisten. |
| 5 / A-05 | **Seleksi dan simulasi:** pemeriksaan kelayakan, input nilai/bukti bila berlaku, rubrik, skor, urutan prioritas, tie-break, kuota dan daftar cadangan. | A-02 dan A-04; metode seleksi disahkan. | Simulasi tidak mengubah hasil resmi. Run resmi memakai snapshot input/aturan dan menghasilkan alasan yang dapat direproduksi. Uji skor sama, batas kuota, pembulatan, peserta tidak layak dan kursi sisa; hasil tidak melampaui kapasitas. |
| 6 / A-06 | **Persetujuan dan pengumuman hasil:** tinjau hasil, persetujuan dua pihak, penjadwalan publikasi, hasil personal diterima/cadangan/tidak diterima dan koreksi hasil berversi. | A-05; approver berbeda dari pengaju. | Hasil draft tidak terlihat oleh wali; publikasi hanya setelah persetujuan dan waktunya tiba. Penerbitan ulang tidak menggandakan keputusan; hasil personal tidak membocorkan peserta lain. Koreksi mencatat alasan, versi dan pemberitahuan. |
| 7 / A-07 | **Sanggah dan pengaduan:** tiket, kategori, bukti, tenggat, penugasan, tanggapan dan eskalasi. | A-01 untuk pengaduan umum; A-06 untuk sanggah hasil. | Wali melihat tiketnya saja. Bukti privat, riwayat tanggapan dan tenggat tercatat; sanggah tidak langsung mengubah hasil. Perubahan keputusan kembali melalui persetujuan dan versi hasil. |
| 8 / A-08 | **Daftar ulang dan pengelolaan kursi:** konfirmasi, pemeriksaan dokumen akhir, status belum/selesai/mengundurkan diri/lewat tenggat, penawaran kursi cadangan. | A-06 dan mekanisme keputusan A-07; kebijakan kursi/tenggat disahkan. | Konfirmasi idempoten; transaksi mencegah kursi ganda/kelebihan kapasitas. Pelepasan dan penawaran ulang kursi mengikuti aturan, masa berlaku, urutan cadangan dan audit; wali mendapat tanda terima. |
| 9 / A-09 | **Laporan dan ekspor:** statistik per sekolah/jalur/tahap, rekap verifikasi, seleksi, daftar ulang dan kursi kosong; CSV serta laporan cetak. | A-01; sumber tahap terkait tersedia. | Ekspor dibatasi sekolah/peran, kolom sensitif diminimalkan, tujuan/pelaku tercatat, dan CSV aman dari formula injection. Total laporan direkonsiliasi dengan sumber; pagination tidak memotong ekspor. Statistik publik tidak mengidentifikasi anak. |
| 10 / A-10 | **Informasi publik dan komunikasi:** identitas resmi, jadwal, kapasitas, syarat, FAQ, kontak bantuan, template pesan, pusat notifikasi dan antrean pengiriman. | A-02; identitas/konten/kanal disetujui. | Konten punya pratinjau dan persetujuan. Notifikasi in-app tersedia minimal pada A-04/A-06/A-08; email memakai antrean dengan status gagal/retry tanpa mengirim ganda. SMS/WhatsApp hanya setelah penyedia dan izin disepakati. |
| 11 / A-11 | **Operasional, audit dan kesiapan produksi:** audit dapat dicari/filter, monitoring, backup/restore, retensi, pemindaian berkas dan prosedur insiden. | Dimulai sejak A-01; gate produksi setelah seluruh alur wajib siap. | Uji pemulihan DB dan berkas berhasil, retensi disahkan, audit keputusan/akses/ekspor lengkap, uji beban dan otorisasi lulus. Tidak ada tombol unduh backup DB berisi data anak untuk staf sekolah. Blokir produksi tetap berlaku sampai checklist rilis disetujui. |
| 12 / A-12 | **Perluasan opsional:** pilihan lintas sekolah, wilayah/jarak, swasta/gelombang, tes/wawancara/beasiswa, pembayaran sah dan integrasi resmi. | Alur inti serta kebijakan/izin/data masing-masing tersedia. | Pilihan lintas sekolah mencegah alokasi kursi ganda sesuai Juknis. Geodata berlisensi dan metode jarak disahkan; pembayaran hanya pada skenario yang sah. Integrasi tidak diklaim aktif tanpa API/otorisasi dan uji nyata. |

Urutan adalah prioritas backlog, bukan keharusan menunggu seluruh daftar:
A-03 dapat dikerjakan setelah A-01 sambil A-02 disiapkan; A-09 dapat dimulai untuk
data verifikasi sebelum seleksi tersedia; A-10 dan A-11 berjalan paralel sesuai
prasyarat. Seleksi tidak boleh dimulai hanya karena menu kuota sudah ada.

#### Fondasi yang diterapkan dan pekerjaan berikutnya

Fondasi **A-01: Akun & akses sekolah** sudah diterapkan untuk pilot dengan langkah
berikut. Jangan langsung menambah mesin ranking.

1. Sepakati matriks admin pusat, admin sekolah, verifikator, dan approver:
   siapa mengelola akun/aturan, memeriksa, mengekspor, dan menerbitkan hasil.
   Peran teknis admin tidak otomatis memperoleh kewenangan keputusan penerimaan.
2. Tambahkan grant/penugasan sekolah dan izin aksi secara aditif; jangan
   membangun ulang akun lama atau otomatis mempromosikan akun wali.
3. Ganti daftar akun hanya-baca menjadi pengelolaan undangan staf, penugasan dan
   pencabutan akses; tidak ada password bersama atau password polos yang dapat dilihat admin.
4. Terapkan pemeriksaan akses yang sama pada halaman, query daftar, aksi POST,
   unduhan dokumen, dashboard, audit dan ekspor; nonaktifkan akses segera saat grant dicabut.
5. Selesaikan MFA staf dan verifikasi kepemilikan email sebelum penggunaan nyata.
   Uji akses lintas sekolah, perubahan role/ID dari klien, undangan kedaluwarsa,
   pencabutan sesi, CSRF dan konflik versi; sediakan uji desktop/ponsel.

A-01 sampai A-04 selesai untuk pilot development; berikutnya A-05 **seleksi dan simulasi**.
Pengembangan tetap menggunakan data uji.

**Operasi A-01:** akun pusat lama wajib memverifikasi email sebelum portal terbuka,
tetapi enroll TOTP opsional dari pengaturan profil akun, bukan setup awal.
Undangan 24 jam dan token email 30 menit disimpan sebagai
hash, GET tidak mengonsumsi token. Enroll MFA perlu password/kode, secret setup
kedaluwarsa 10 menit. Secret tersimpan terenkripsi AES-256-GCM; kunci berada pada
storage privat dan harus ikut backup aman. Delapan recovery code hanya
ditampilkan sekali dan disimpan sebagai hash, masing-masing sekali pakai.
Perubahan akses/role/nonaktif mencabut sesi dan melepas tugas; data keputusan
lama tidak dihapus. Penghapusan sekolah belum dipakai mencabut sesi staf terdampak
dan membatalkan undangan terkait. Pengguna dapat menonaktifkan atau mengganti MFA
melalui UI dengan sesi MFA terverifikasi, password dan konfirmasi, mencabut
secret/kode/sesi lain. Pengaturan hanya membuat secret setelah pengguna memilih
Atur 2FA; batal atau setup kedaluwarsa tidak mengaktifkan 2FA. Login akun yang
telah mengaktifkan 2FA tetap memerlukan challenge, termasuk akun wali.
Transport email file tetap lokal dan tidak dianggap pengiriman ke internet.
Tombol skip development dihapus; flag skip sesi lama tidak melewati challenge.
Akun dengan 2FA aktif tidak dinonaktifkan otomatis, sementara akun baru tetap
nonaktif sampai pengguna mengonfirmasi aktivasi. Scope sekolah dan gate produksi
tetap berlaku. Kebijakan MFA wajib untuk peran berisiko merupakan gate rilis
yang harus disepakati kembali sebelum penerimaan nyata, bukan perilaku pilot saat ini.

#### Operasi A-02: aturan berversi

- Tahun ajaran dari periode lama dimigrasikan sebagai label tanpa tanggal
  fiktif. Lengkapi rentangnya; label dipakai periode dan tanggal dipakai paket
  terkunci. Tahun belum dipakai dapat dihapus dengan konfirmasi. Arsip tahun
  mencegah paket baru/transisi review, tidak diam-diam menutup periode terbit.
- Paket disusun admin pusat pada periode yang belum pernah dipakai. Rombel 1–100,
  nama unik, kursi bulat ≤ batas rombel yang dimasukkan dari Juknis, total tepat
  kapasitas; kuota memuat tepat semua jalur dan total kursi juga tepat kapasitas.
  UI tambah/hapus rombel memiliki fallback POST tanpa JS.
- Validasi baseline Permendikdasmen 3/2025 menggunakan perkalian bilangan bulat:
  SD domisili ≥70%/afirmasi ≥15%; SMP ≥40%/20%/25%; SMA ≥30%/30%/30%; mutasi ≤5%.
  Tidak ada prestasi SD; persentase tampilan dua desimal bukan pembulatan
  validasi. Swasta mandiri tidak dikenakan ambang negeri. Kuota efektif/sisa kursi
  diisi manual dan rujukan pasalnya wajib; keabsahan naskah tetap review manusia.
- Jadwal wajib pendaftaran, verifikasi, perbaikan, seleksi, pengumuman, sanggah,
  daftar ulang dengan zona periode. Pendaftaran mengikuti periode. Verifikasi
  boleh overlap pendaftaran/perbaikan dan mencakup penutupan pendaftaran;
  perbaikan dalam verifikasi. Seleksi sesudah ketiganya selesai, kemudian
  pengumuman → sanggah → daftar ulang berurutan. Batas kalender awal hingga
  akhir tahun ajaran; tahap selain pendaftaran belum menjalankan modul otomatis.
- Juknis memuat nomor/penerbit/versi/tanggal/mulai-akhir berlaku, URL HTTPS tanpa
  kredensial, serta catatan prioritas/tie-break/pembulatan/kursi sisa. Masa berlaku
  mencakup seluruh tahap. Server tidak mengunduh URL; upload salinan PDF belum
  tersedia. Grant aplikasi tidak menggantikan kewenangan legal pengesah.
- Draf → pending → approved → published; approver aktif bertanda can_approve
  untuk sekolah paket berbeda dari penyusun/pengaju. Return membuka edit dan
  pengajuan ulang. Catatan wajib, fingerprint sumber/payload, optimistic lock,
  transaksi dan riwayat setiap aksi. Pencabutan grant sebelum publikasi ditolak;
  approved dapat diajukan ulang kepada approver yang masih berwenang.
- Paket pertama mengarsipkan periode. Published tetap arsip sampai toggle
  katalog diaktifkan. Identitas/periode/jalur dibekukan saat diajukan/terbit,
  paket published tidak diedit dan tidak dihapus. Versi baru tetap perlu review;
  mengganti versi hanya sebelum periode pernah dipakai. Setelah ada draf,
  salin periode baru, bukan ubah aturan kiriman. Snapshot operational ikut
  rule_snapshot_json saat submit; akun/dokumen/keputusan lama tetap utuh.
- Periode pilot tanpa paket tetap berjalan seperti sebelumnya, bukan dianggap
  telah disetujui. Periode dengan paket diperiksa pada katalog/detail/mutasi/
  master/dashboard/CLI; aktivasi lewat seed/SQL tidak melewati gate paket.
  Import/salin tidak membawa klaim persetujuan operational. Gate produksi tetap
  tertutup. Uji kapasitas/kuota/jadwal/CSRF/versi/scope/history/produksi tersedia
  pada tests/operational_flow.py dengan DB privat sementara.

#### Operasi A-03: pendaftar dan antrean

- Menu Pendaftar dan Antrean kerja, 25 baris/halaman; cari nama/nomor kiriman,
  bukan seluruh JSON wali/alamat. Filter sekolah/periode/tahun/jalur/status/
  verifikator/duplikasi, sort terlama/terbaru/nama/nomor/tenggat, pagination
  mempertahankan filter. Daftar dan ringkasan hanya kiriman terkirim dalam scope.
- Belum ditugaskan: belum ada keputusan dan petugas kosong. Sedang ditangani:
  sudah ditugaskan, belum ada keputusan. Sudah diputuskan mencakup tiga keputusan
  verifikasi, bukan hasil seleksi. Ringkasan mengikuti filter selain antrean/page.
  Terlambat dapat tumpang tindih dengan dua antrean belum selesai.
- Keputusan kebijakan: tenggat akhir verifikasi dari paket pada snapshot kiriman;
  tanpa paket tampil “Tenggat belum diatur”. Tidak memakai SLA jam atau tanggal
  penutupan pendaftaran sebagai fallback. Indikator bukan penolakan otomatis
  atau pembatasan aksi setelah tenggat; penerapan koreksi rinci ada di A-04.
- Verifikator dapat mengambil tugas kosong di sekolah grant aktifnya. Ringkasan
  sebelum mengambil hanya nomor/periode/sekolah/jalur/waktu/tenggat, tanpa
  identitas/dokumen/duplikasi. Grant/status/versi/petugas diperiksa lagi dalam
  transaksi; dua pengambil menghasilkan satu sukses dan satu konflik 409.
  Sesudah mengambil, akses detail/dokumen mengikuti penugasan biasa.
- Admin pusat/sekolah boleh mengganti/melepas petugas sesuai scope; pengalihan/
  pelepasan petugas yang sudah ada wajib catatan 5–2000 karakter. Riwayat internal
  menyimpan petugas lama/baru, aktor, waktu, aksi dan catatan. Perubahan grant/
  peran/nonaktif juga merekam pelepasan. Riwayat detail maksimal 50 terbaru;
  tidak mengarang riwayat sebelum migrasi A-03.
- NISN nonkosong sama ATAU nama/tanggal lahir nonkosong sama, pada tahun ajaran
  sama, menandai potensi duplikasi. Nama dibandingkan tanpa kapital/spasi
  berulang, bukan fuzzy. Pasangan hanya sesama kiriman dalam scope pemeriksaan;
  verifikator tidak mendapatkan tanda atau tautan dari tugas petugas lain.
  Detail maksimal 25 pasangan dengan alasan, tinjauan manual melalui dokumen
  dan catatan verifikasi. Tidak menghapus/menolak otomatis dan belum ada status
  penyelesaian temuan duplikasi tersendiri. Daftar lintas periode bukan otomatis
  pelanggaran; kebijakan pilihan sekolah tetap memerlukan Juknis.
- Skema/indeks aditif; snapshot/data peserta tetap utuh. Tes HTTP memakai DB
  sementara: konflik pengambilan, CSRF, grant/dokumen/riwayat, filter/pagination,
  duplikasi/tahun, tenggat dan produksi. Pencocokan saat baca belum memiliki
  indeks identitas khusus atau uji beban produksi. Gate produksi tetap tertutup.

#### Operasi A-04: checklist, koreksi dan revisi

- Checklist semua dokumen jalur ditambah empat kriteria tetap: identitas,
  domisili, kelayakan jalur dan konsistensi data. Status Belum diperiksa, Valid,
  Perlu perbaikan, Tidak valid atau Tidak berlaku. Catatan item diperiksa
  5–2000 karakter; Belum diperiksa boleh kosong. Tidak berlaku hanya untuk
  dokumen opsional yang tidak ada, bukan kriteria wajib/dokumen yang diunggah.
- Valid mensyaratkan seluruh item Valid/Tidak berlaku yang sah. Perlu perbaikan/
  Tidak valid harus mempunyai item dengan status yang sama. Simpan checklist
  saja tidak mengambil keputusan; setelah keputusan, perubahan harus bersama
  keputusan untuk menjaga konsistensi. Kelayakan manual, bukan seleksi otomatis.
- Minta koreksi terbatas hanya pada kriteria/dokumen Perlu perbaikan; pilih kolom/
  berkas spesifik dan alasan 5–2000 karakter. Jalur, periode, aturan dan nomor
  terkunci. Keputusan Perlu perbaikan biasa tidak otomatis membuka koreksi.
  Satu permintaan terbuka per kiriman; penugasan verifikator tetap.
- Kebijakan disepakati: permintaan, simpan, unggah dan kirim ulang **hanya**
  selama tahap Perbaikan dari paket pada snapshot, awal inklusif/akhir eksklusif.
  Tenggat otomatis akhir tahap, tanpa fallback/perpanjangan manual. Kiriman
  tanpa paket tetap dapat diperiksa, tetapi tidak dapat membuka koreksi.
  Gunakan periode baru disetujui untuk alur tersebut. Arsip katalog tidak
  mengubah jadwal snapshot. Selama permintaan terbuka panitia menunggu wali;
  sesudah tenggat dapat memutuskan versi terkirim terakhir dan menutup permintaan.
- Wali hanya mengubah kolom/berkas yang dipilih. Validasi lengkap tetap berlaku;
  setiap kolom terpilih harus berubah dan setiap berkas terpilih diunggah ulang.
  Simpan draf eksplisit tanpa autosave, unggahan privat PDF/JPG/PNG maksimal
  2 MB dengan validasi jenis/ukuran/integritas yang sama dengan kiriman awal.
  Simpan/unggah ber-versi; kirim ulang idempoten dan transaksional.
- Kirim ulang membuat revisi immutable, reset checklist dan keputusan aktif
  menjadi menunggu pemeriksaan ulang; riwayat keputusan tidak dihapus. Data/
  dokumen awal, profil peserta, snapshot, nomor dan tanda terima awal tetap utuh.
  Panitia/wali dapat melihat arsip revisi hanya baca dan riwayat 50 terakhir.
  Tidak mengarang checklist keputusan sebelum A-04. Kartu/antrean/duplikasi
  memakai revisi terkirim terbaru, tidak memakai draf koreksi.
- Staf mengikuti scope sekolah/penugasan untuk revisi dan berkas yang terkirim;
  tidak dapat membuka berkas draf koreksi. Wali hanya miliknya. Pengganti tidak
  menghapus berkas versi sebelumnya. Retensi fisik tetap memerlukan kebijakan A-11.
- Notifikasi in-app persisten untuk permintaan, kirim ulang dan keputusan;
  dashboard lima belum dibaca, halaman notifikasi 50 terakhir, tandai dibaca
  hanya pemilik. Catatan pemeriksaan terlihat wali; catatan penugasan A-03 tetap
  internal. Email/SMS/WhatsApp belum dikirim otomatis.
- Skema aditif, CSRF/izin/versi diperiksa dalam mutasi, produksi tetap diblokir.
  Tes HTTP privat mencakup checklist/keputusan, revisi berulang, batas waktu,
  tanpa paket, scope/dokumen/notifikasi, konflik versi, upload/integritas,
  original tetap utuh dan pemeriksaan ulang. Valid bukan keputusan diterima.

#### Rencana pengelompokan menu admin

- **Dashboard:** ringkasan dibatasi kewenangan sekolah, antrean, tenggat dan kursi.
- **Pendaftar:** daftar, detail, penugasan, verifikasi dan permintaan perbaikan.
- **Master data:** sekolah, tahun ajaran/periode, rombel, kuota/jalur dan paket aturan.
- **Seleksi & hasil:** simulasi, run, persetujuan dan pengumuman.
- **Sanggah & pengaduan**, **Daftar ulang**, **Laporan**.
- **Pengaturan:** akun/peran, konten informasi, notifikasi dan konfigurasi operasional.
- **Audit:** pencarian riwayat sesuai hak akses; bukan akses penuh log teknis untuk semua staf.

Menu dapat dikelompokkan dalam dropdown agar header tidak terlalu panjang.
Navigasi menyesuaikan izin, tetapi menyembunyikan menu bukan pengganti otorisasi server.
Pemisahan status wajib: **status pengiriman/revisi**, **status verifikasi**,
**hasil seleksi**, dan **status daftar ulang** tidak digabung menjadi satu status.
Valid berarti berkas memenuhi pemeriksaan, bukan otomatis diterima.

#### Keputusan kebijakan yang belum boleh diasumsikan

- Juknis Serdang Bedagai dan tahun ajaran efektif, pejabat pengesah, kapasitas
  rombel, persentase efektif, pembulatan, prioritas, tie-break dan kursi sisa.
- Identitas/kewenangan legal approver independen; alur teknis sudah disepakati:
  approver sekolah berbeda dari penyusun/pengaju, admin pusat menerbitkan.
  Satu staf boleh beberapa sekolah sudah diimplementasikan.
- Satu atau beberapa pilihan sekolah; perpindahan pilihan dan pencegahan kursi ganda.
- Kolom/berkas yang boleh dikoreksi, tenggat, prosedur sanggah dan daftar ulang.
- Dasar pemrosesan/retensi, penerima laporan, kanal notifikasi, dan target beban.

Angka kuota, rumus ranking, jarak, dan tenggat tidak ditanam sebagai kebijakan
default yang seolah telah disahkan. Lokasi GPS/formulir bukan bukti domisili;
peta dan layanan geocoding internal belum tersedia untuk seleksi jarak.
SQLite tetap dipakai untuk pengembangan; keputusan mempertahankan atau mengganti
database produksi berdasarkan uji beban tulis dan kebutuhan operasional, bukan asumsi.

## 16. Kriteria penerimaan MVP

- Pendaftar dapat menyelesaikan satu alur dari informasi sampai tanda terima pada ponsel dan desktop.
- Draf tersimpan dan dapat dilanjutkan; kegagalan unggah memiliki opsi coba lagi tanpa menggandakan berkas.
- Paket aturan negeri tidak dapat terbit jika persentase, kapasitas, syarat atau jadwal melanggar validasi yang dikonfigurasi.
- Hasil seleksi dapat direproduksi dan setiap keputusan memiliki alasan, aturan versi, serta jejak audit.
- Perubahan aturan setelah tayang memerlukan versi baru dan persetujuan; dampak kepada pendaftar dapat ditinjau.
- Peran yang tidak berwenang tidak dapat melihat dokumen sensitif, mengekspor data, mengubah aturan atau menerbitkan hasil.
- Pendaftar dapat melihat alasan perbaikan/ketidaklayakan, mengajukan keberatan, dan melacak status tiket.
- Layar inti dapat digunakan dengan keyboard, pembaca layar, pembesaran teks, dan koneksi ponsel yang lambat.
- Data demo dan laporan publik tidak memuat identitas pribadi.

## 17. Pertanyaan terbuka untuk keputusan pemilik produk

1. Apakah produk pertama ditujukan sebagai SaaS untuk sekolah, sistem dinas kabupaten/kota/provinsi, atau keduanya?
2. Wilayah pilot dan tahun ajaran mana yang menjadi sumber Juknis pertama?
3. Untuk sekolah swasta, apakah kebijakan produk berupa penerimaan mandiri penuh atau juga menyediakan jalur SPMB/kerja sama pemerintah?
4. Apakah diperlukan pembayaran daring pada rilis awal? Siapa penerima dana dan bagaimana perlakuan pembebasan biaya?
5. Kanal notifikasi yang boleh dipakai, serta sumber layanan OTP/WhatsApp/SMS apa yang sudah tersedia?
6. Apakah ada sistem identitas, data sekolah, atau kanal pengaduan yang wajib diintegrasikan?
7. Siapa pejabat yang menyetujui paket aturan dan hasil pada tiap jenis penyelenggara?

## 18. Rujukan kebijakan

- [Permendikdasmen No. 3 Tahun 2025 tentang Sistem Penerimaan Murid Baru — naskah peraturan](https://peraturan.go.id/files/Permendikdasmen-no-3-tahun-2025.pdf)
- [Halaman resmi SPMB Kemendikdasmen, termasuk arahan TA 2026/2027](https://pdm.kemendikdasmen.go.id/spmb)
- [FAQ SPMB 2026 Kemendikdasmen](https://pdm.kemendikdasmen.go.id/faq-spmb-2026)
- [Informasi data SPMB Kemendikdasmen](https://pelayanan.data.kemendikdasmen.go.id/informasi-data-spmb)

**Catatan pemutakhiran:** halaman resmi SPMB menyebut pelaksanaan TA 2026/2027 mengacu pada Permendikdasmen No. 3 Tahun 2025 dan meminta Pemda melaksanakan berdasarkan kewenangannya. Sebelum tiap periode dibuka, pemilik kebijakan harus memeriksa perubahan peraturan, surat edaran, FAQ resmi, serta Juknis provinsi/kabupaten/kota terbaru.
