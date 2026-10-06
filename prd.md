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
editor jalur dan dokumen, daftar akun admin, dan audit.
Akun uji dibuat CLI, bukan register publik. Hasil terlihat oleh wali tetapi bukan
keputusan penerimaan; data terkirim tetap terkunci. Koreksi/pengiriman ulang,
persetujuan dua pihak untuk penerbitan aturan, admin terbatas sekolah, MFA, dan
pemisahan tugas belum diimplementasikan. Portal admin diblokir pada produksi.
Dropdown master memisahkan Sekolah dan Periode pendaftaran. Edit/hapus hanya
sebelum data pernah dipakai pendaftaran; sesudah itu hanya arsip atau salin menjadi
periode baru. Draf yang dibatalkan tidak membuka kembali hak edit/hapus master.
Sekolah dengan periode harus menghapus periode yang belum dipakai terlebih dahulu
sebelum sekolah dapat dihapus. Arsip sekolah mengarsipkan seluruh periodenya,
tanpa menghapus data peserta. Periode baru selalu arsip dan untuk pengujian.

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
