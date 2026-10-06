<?php
declare(strict_types=1);

function admissionTemplate(string $mode, string $level, DateTimeImmutable $now): array
{
    if (!in_array($mode, ['negeri', 'swasta'], true) || !in_array($level, ['SD', 'SMP', 'SMA'], true)) {
        throw new InvalidArgumentException('Template harus negeri/swasta dengan jenjang SD/SMP/SMA.');
    }
    $now = $now->setTimezone(new DateTimeZone('Asia/Jakarta'));
    $commonDocuments = [
        ['code' => 'kartu-keluarga', 'label' => 'Kartu keluarga — gunakan berkas fiktif untuk demo', 'required' => true],
        ['code' => 'akta-kelahiran', 'label' => 'Akta kelahiran — gunakan berkas fiktif untuk demo', 'required' => true],
    ];
    if ($level !== 'SD') {
        $commonDocuments[] = ['code' => 'kelulusan', 'label' => 'Ijazah / surat keterangan lulus — berkas fiktif untuk demo', 'required' => true];
    }
    $pathway = static function (string $code, string $name, string $description, array $extra = []) use ($commonDocuments): array {
        return ['code' => $code, 'name' => $name, 'description' => $description, 'documents' => array_merge($commonDocuments, $extra)];
    };
    if ($mode === 'negeri') {
        $pathways = [
            $pathway('domisili', 'Domisili (sebelumnya zonasi)',
                'Untuk calon peserta yang berdomisili dalam wilayah penerimaan yang ditetapkan pemerintah daerah. Wilayah, masa berlaku bukti domisili, dan prioritas wajib mengikuti Juknis; contoh ini tidak menghitung jarak atau menentukan kelayakan.'),
            $pathway('afirmasi', 'Afirmasi',
                'Untuk calon peserta dari keluarga ekonomi tidak mampu dan calon peserta penyandang disabilitas sesuai ketentuan. Jenis bukti mengikuti kategori dan Juknis; tidak semua peserta wajib menyerahkan bukti bantuan sosial maupun bukti disabilitas sekaligus.',
                [['code' => 'bukti-afirmasi', 'label' => 'Bukti afirmasi sesuai kategori (ekonomi / disabilitas) — fiktif untuk demo', 'required' => true]]),
        ];
        if ($level !== 'SD') {
            $pathways[] = $pathway('prestasi', 'Prestasi',
                'Untuk prestasi akademik atau nonakademik sesuai ketentuan. Bukti, masa prestasi, bobot rapor/tes yang berlaku, dan pemeringkatan harus ditetapkan dalam Juknis; tidak ada skor otomatis pada template ini.',
                [['code' => 'bukti-prestasi', 'label' => 'Bukti prestasi sesuai kategori: rapor / sertifikat / bukti lain — fiktif untuk demo', 'required' => true]]);
        }
        $pathways[] = $pathway('mutasi', 'Mutasi',
            'Untuk perpindahan tugas orang tua/wali serta anak guru sesuai ketentuan. Bukti mengikuti kategori dan Juknis; tidak seluruh pendaftar wajib memiliki surat perpindahan tugas dan surat anak guru sekaligus.',
            [['code' => 'bukti-mutasi', 'label' => 'Bukti mutasi sesuai kategori: perpindahan tugas / anak guru — fiktif untuk demo', 'required' => true]]);
        $reference = 'Baseline Permendikdasmen No. 3 Tahun 2025. Template contoh; Juknis daerah/tahun ajaran, kuota, kelayakan, dan persetujuan penyelenggara belum ditetapkan. Bukan penerimaan nyata.';
    } else {
        $pathways = [
            $pathway('reguler', 'Reguler / Mandiri',
                'Contoh penerimaan mandiri sekolah swasta. Sekolah menetapkan jadwal, syarat, biaya, serta proses seleksi sesuai jenjang. Bukan jalur wajib nasional dan tidak otomatis memakai kuota sekolah negeri.'),
        ];
        if ($level !== 'SD') {
            $pathways[] = $pathway('prestasi', 'Prestasi sekolah',
                'Jalur opsional jika sekolah menyediakan penerimaan berdasarkan prestasi. Jenis prestasi, bukti, seleksi, dan manfaat mengikuti kebijakan sekolah; bukan kewajiban nasional.',
                [['code' => 'bukti-prestasi', 'label' => 'Bukti prestasi sesuai kebijakan sekolah — fiktif untuk demo', 'required' => true]]);
        }
        $pathways[] = $pathway('beasiswa', 'Beasiswa / Bantuan biaya',
            'Contoh jalur opsional bila sekolah menyediakan program bantuan biaya. Kriteria, bukti, cakupan bantuan, dan keputusan harus ditetapkan sekolah; memilih jalur ini bukan jaminan memperoleh beasiswa.',
            [['code' => 'bukti-beasiswa', 'label' => 'Bukti pengajuan bantuan sesuai kebijakan sekolah — fiktif untuk demo', 'required' => true]]);
        $reference = 'Template penerimaan mandiri swasta, bukan standar jalur nasional yang wajib. Kebijakan sekolah, biaya, dan program bantuan belum disahkan. Kerja sama SPMB Pemda membutuhkan konfigurasi tersendiri.';
    }
    return [
        'code' => 'contoh-' . $mode . '-' . strtolower($level),
        'organizer' => ($mode === 'negeri' ? 'Pemerintah Daerah Contoh' : 'Yayasan Pendidikan Contoh') . ' (DEMO)',
        'school' => $level . ' ' . ($mode === 'negeri' ? 'Negeri' : 'Swasta') . ' Contoh (DEMO)',
        'level' => $level,
        'academic_year' => $now->format('Y') . '/' . ((int) $now->format('Y') + 1),
        'timezone' => 'Asia/Jakarta',
        'opens_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
        'closes_at' => $now->modify('+30 days')->format('Y-m-d H:i:s'),
        'is_demo' => true,
        'admission_mode' => $mode === 'negeri' ? 'public_spmb' : 'private_independent',
        'privacy_notice' => 'Template contoh untuk pengujian. Gunakan identitas dan dokumen fiktif saja. Persyaratan merupakan ilustrasi, bukan checklist resmi; wajib ditinjau penyelenggara sebelum penerimaan nyata.',
        'help_contact' => 'Hubungi pengembang lokal. Kontak penyelenggara nyata belum ditetapkan.',
        'rule_reference' => $reference,
        'pathways' => $pathways,
    ];
}
