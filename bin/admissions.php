<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    require dirname(__DIR__) . '/app/admissions.php';
    $command = $argv[1] ?? '';
    if ($command === 'list') {
        foreach ($db->query('SELECT * FROM admission_periods ORDER BY created_at DESC')->fetchAll() as $period) {
            echo $period['code'] . "\t" . $period['school'] . "\t" . periodState($period)
                . "\t" . ($period['is_demo'] ? 'DEMO' : 'NON-DEMO') . PHP_EOL;
        }
    } elseif ($command === 'demo') {
        if ($config['environment'] !== 'development') {
            throw new InvalidArgumentException('Periode demo hanya boleh dibuat pada development.');
        }
        $zone = new DateTimeZone('Asia/Jakarta');
        $now = new DateTimeImmutable('now', $zone);
        $configuration = [
            'code' => 'demo-pendaftaran',
            'organizer' => 'Penyelenggara Contoh (DEMO)',
            'school' => 'Sekolah Contoh SMP (DEMO)',
            'level' => 'SMP',
            'academic_year' => $now->format('Y') . '/' . ((int) $now->format('Y') + 1),
            'timezone' => 'Asia/Jakarta',
            'opens_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            'closes_at' => $now->modify('+30 days')->format('Y-m-d H:i:s'),
            'is_demo' => true,
            'privacy_notice' => 'Periode ini hanya untuk pengujian. Gunakan identitas dan dokumen fiktif, bukan data pribadi anak. Data digunakan untuk mencoba alur registrasi dan dapat dihapus saat pengujian selesai.',
            'help_contact' => 'Hubungi pengembang lokal untuk bantuan pengujian.',
            'rule_reference' => 'Simulasi teknis; bukan Juknis resmi dan tidak melakukan seleksi penerimaan.',
            'pathways' => [
                ['code' => 'simulasi', 'name' => 'Jalur simulasi', 'description' => 'Jalur untuk mencoba alur formulir dan dokumen, bukan jalur penerimaan nyata.',
                    'documents' => [
                        ['code' => 'identitas-demo', 'label' => 'Dokumen identitas fiktif (DEMO)', 'required' => true],
                        ['code' => 'sekolah-demo', 'label' => 'Dokumen sekolah asal fiktif (DEMO)', 'required' => false],
                    ]],
            ],
        ];
        $id = insertAdmissionPeriod($db, $configuration);
        echo "Periode DEMO dibuat: $id\n";
    } elseif ($command === 'import' && isset($argv[2])) {
        if (!is_file($argv[2]) || filesize($argv[2]) > 100000) {
            throw new InvalidArgumentException('File konfigurasi tidak ditemukan atau melebihi 100 KB.');
        }
        $contents = file_get_contents($argv[2]);
        if ($contents === false) {
            throw new RuntimeException('File konfigurasi tidak dapat dibaca.');
        }
        $id = insertAdmissionPeriod($db, admissionData($contents));
        echo "Periode dibuat: $id\n";
    } else {
        throw new InvalidArgumentException("Penggunaan:\nphp bin/admissions.php demo\nphp bin/admissions.php import /path/periode.json\nphp bin/admissions.php list");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Gagal: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
