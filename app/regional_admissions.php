<?php
declare(strict_types=1);

function serdangBedagaiSchools(): array
{
    $raw = file_get_contents(dirname(__DIR__) . '/data/serdang-bedagai-smp.json');
    if ($raw === false) {
        throw new RuntimeException('Snapshot sekolah Serdang Bedagai tidak dapat dibaca.');
    }
    $catalogue = admissionData($raw);
    $schools = $catalogue['schools'] ?? [];
    $npsns = [];
    foreach ($schools as $school) {
        if (!is_array($school) || !isset($school['npsn'], $school['name'], $school['district'], $school['district_code'], $school['general_spmb'])
            || !preg_match('/\A[0-9]{8}\z/', $school['npsn']) || in_array($school['npsn'], $npsns, true)
            || !is_bool($school['general_spmb']) || !preg_match('/\A0721[0-9]{2}\z/', $school['district_code'])) {
            throw new RuntimeException('Snapshot sekolah Serdang Bedagai tidak valid.');
        }
        $npsns[] = $school['npsn'];
    }
    if (count($schools) !== 41 || count(array_filter($schools, fn(array $school): bool => $school['general_spmb'])) !== 40) {
        throw new RuntimeException('Jumlah sekolah snapshot Serdang Bedagai tidak sesuai.');
    }
    return $catalogue;
}

function seedSerdangBedagai(PDO $db, array $config): int
{
    if ($config['environment'] !== 'development') {
        throw new InvalidArgumentException('Periode regional DEMO hanya boleh disiapkan pada development.');
    }
    $catalogue = serdangBedagaiSchools();
    $ids = [];
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    foreach ($catalogue['schools'] as $school) {
        if (!$school['general_spmb']) {
            continue;
        }
        $code = 'sergai-smp-' . $school['npsn'] . '-demo';
        $statement = $db->prepare('SELECT id, config_json FROM admission_periods WHERE code = ?');
        $statement->execute([$code]);
        $existing = $statement->fetch();
        if ($existing) {
            $configuration = admissionData($existing['config_json']);
            if (($configuration['npsn'] ?? '') !== $school['npsn']
                || ($configuration['admission_mode'] ?? '') !== 'public_spmb'
                || ($configuration['regency'] ?? '') !== $catalogue['regency']) {
                throw new RuntimeException('Kode periode regional sudah digunakan oleh konfigurasi yang berbeda: ' . $code);
            }
            $ids[] = $existing['id'];
            continue;
        }
        $configuration = admissionTemplate('negeri', 'SMP', $now);
        $configuration['code'] = $code;
        $configuration['school'] = $school['name'];
        $configuration['organizer'] = 'Pemerintah Kabupaten Serdang Bedagai (simulasi; belum disahkan)';
        $configuration['npsn'] = $school['npsn'];
        $configuration['district'] = $school['district'];
        $configuration['regency'] = $catalogue['regency'];
        $configuration['province'] = $catalogue['province'];
        $configuration['school_source'] = 'https://referensi.data.kemendikdasmen.go.id/pendidikan/dikdas/'
            . $school['district_code'] . '/3/jf/6/s1';
        $configuration['school_verified_at'] = $catalogue['retrieved_at'];
        $configuration['help_contact'] = 'Kontak panitia dan Juknis Kabupaten Serdang Bedagai belum ditetapkan. Hubungi pengembang untuk simulasi.';
        $configuration['rule_reference'] .= ' Nama dan NPSN sekolah berasal dari Referensi Data Kemendikdasmen, snapshot '
            . $catalogue['retrieved_at'] . '; pencantuman sekolah bukan bukti keikutsertaan atau otorisasi penerimaan.';
        $ids[] = insertAdmissionPeriod($db, $configuration);
    }
    admissionTransaction($db, function () use ($db, $ids): void {
        $db->exec('INSERT INTO admission_period_availability (period_id, enabled) SELECT id, 0 FROM admission_periods WHERE 1
            ON CONFLICT(period_id) DO UPDATE SET enabled = 0');
        $enable = $db->prepare('UPDATE admission_period_availability SET enabled = 1 WHERE period_id = ?');
        foreach ($ids as $id) {
            $enable->execute([$id]);
        }
        audit($db, 'admission.serdang_bedagai_scope_activated');
    });
    return count($ids);
}
