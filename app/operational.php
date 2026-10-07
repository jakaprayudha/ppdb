<?php
declare(strict_types=1);

function operationalStages(): array
{
    return ['registration' => 'Pendaftaran', 'verification' => 'Verifikasi', 'correction' => 'Perbaikan',
        'selection' => 'Seleksi', 'announcement' => 'Pengumuman', 'appeal' => 'Sanggah', 'reenrollment' => 'Daftar ulang'];
}

function operationalStatus(string $status): string
{
    return match ($status) {
        'draft' => 'Draf', 'pending' => 'Menunggu persetujuan', 'returned' => 'Dikembalikan',
        'approved' => 'Disetujui', 'published' => 'Diterbitkan', 'superseded' => 'Versi terdahulu',
        default => throw new RuntimeException('Status paket aturan tidak dikenal.'),
    };
}

function operationalText(mixed $value, string $label, int $maximum = 2000): string
{
    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        throw new AdmissionProblem("$label wajib diisi dengan teks valid (maksimal $maximum karakter).", 422);
    }
    return trim($value);
}

function operationalInteger(mixed $value, string $label, int $minimum = 0, int $maximum = 100000): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new AdmissionProblem("$label harus berupa bilangan bulat.", 422);
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
    if ($number === false) {
        throw new AdmissionProblem("$label harus $minimum–$maximum.", 422);
    }
    return $number;
}

function operationalDate(mixed $value, string $label, string $format = 'Y-m-d', string $timezone = 'Asia/Jakarta'): DateTimeImmutable
{
    if (!is_string($value)) {
        throw new AdmissionProblem("$label tidak valid.", 422);
    }
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone($timezone));
    if (!$date || $date->format($format) !== $value) {
        throw new AdmissionProblem("$label harus berformat $format dan tanggalnya valid.", 422);
    }
    return $date;
}

function saveAcademicYear(PDO $db, int $actor, ?string $id, ?int $version, array $values): string
{
    return admissionTransaction($db, function () use ($db, $actor, $id, $version, $values): string {
        requireCentral($db, $actor);
        $label = operationalText($values['label'] ?? null, 'Tahun ajaran', 9);
        if (!preg_match('/\A([0-9]{4})\/([0-9]{4})\z/', $label, $matches) || (int) $matches[2] !== (int) $matches[1] + 1) {
            throw new AdmissionProblem('Tahun ajaran harus dua tahun berurutan, contoh 2026/2027.', 422);
        }
        $start = operationalDate($values['starts_on'] ?? null, 'Awal tahun ajaran');
        $end = operationalDate($values['ends_on'] ?? null, 'Akhir tahun ajaran');
        if ($start >= $end || $start->format('Y') !== $matches[1] || $end->format('Y') !== $matches[2]) {
            throw new AdmissionProblem('Rentang tahun ajaran harus berurutan dan sesuai label tahunnya.', 422);
        }
        $duplicate = $db->prepare('SELECT 1 FROM academic_years WHERE label=? AND id<>?');
        $duplicate->execute([$label, $id ?? '']);
        if ($duplicate->fetchColumn()) {
            throw new AdmissionProblem('Tahun ajaran sudah ada; lengkapi data yang ada.', 422);
        }
        if ($id) {
            $year = academicYear($db, $id);
            if ((int) $year['version'] !== $version) {
                throw new AdmissionProblem('Tahun ajaran telah berubah. Muat ulang.', 409);
            }
            $used = $db->prepare('SELECT 1 FROM operational_rule_packs WHERE year_id=?');
            $used->execute([$id]);
            if ($used->fetchColumn()) {
                throw new AdmissionProblem('Tahun ajaran sudah dipakai paket aturan; tanggal/label tidak dapat ditimpa.', 409);
            }
            $period = $db->prepare('SELECT 1 FROM admission_periods WHERE academic_year=?');
            $period->execute([$year['label']]);
            if ($label !== $year['label'] && $period->fetchColumn()) {
                throw new AdmissionProblem('Label sudah dipakai periode. Label tidak dapat diganti.', 409);
            }
            $db->prepare('UPDATE academic_years SET label=?,starts_on=?,ends_on=?,version=version+1 WHERE id=?')
                ->execute([$label, $values['starts_on'], $values['ends_on'], $id]);
        } else {
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO academic_years(id,label,starts_on,ends_on) VALUES(?,?,?,?)')
                ->execute([$id, $label, $values['starts_on'], $values['ends_on']]);
        }
        audit($db, 'admin.academic_year_saved:' . $id, $actor);
        return $id;
    });
}

function academicYear(PDO $db, string $id): array
{
    $statement = $db->prepare('SELECT * FROM academic_years WHERE id=?');
    $statement->execute([$id]);
    return $statement->fetch() ?: throw new AdmissionProblem('Tahun ajaran tidak ditemukan.', 404);
}

function academicYearAction(PDO $db, int $actor, string $id, int $version, string $action): void
{
    admissionTransaction($db, function () use ($db, $actor, $id, $version, $action): void {
        requireCentral($db, $actor);
        $year = academicYear($db, $id);
        if ((int) $year['version'] !== $version) {
            throw new AdmissionProblem('Tahun ajaran telah berubah. Muat ulang.', 409);
        }
        if ($action === 'delete') {
            $statement = $db->prepare('SELECT 1 FROM admission_periods WHERE academic_year=? UNION ALL SELECT 1 FROM operational_rule_packs WHERE year_id=?');
            $statement->execute([$year['label'], $id]);
            if ($statement->fetchColumn()) {
                throw new AdmissionProblem('Tahun ajaran sudah dipakai. Gunakan arsip, bukan hapus.', 409);
            }
            if (input('confirm_delete') !== '1') {
                throw new AdmissionProblem('Konfirmasi penghapusan diperlukan.', 422);
            }
            $db->prepare('DELETE FROM academic_years WHERE id=?')->execute([$id]);
        } elseif (in_array($action, ['archive', 'activate'], true)) {
            $db->prepare('UPDATE academic_years SET enabled=?,version=version+1 WHERE id=?')->execute([(int) ($action === 'activate'), $id]);
        } else {
            throw new AdmissionProblem('Aksi tahun ajaran tidak valid.', 422);
        }
        audit($db, 'admin.academic_year_' . $action . ':' . $id, $actor);
    });
}

function operationalSource(PDO $db, string $periodId): array
{
    $statement = $db->prepare('SELECT p.*,l.school_id,s.npsn,s.name,s.mode,s.province,s.city,s.district,s.address
        FROM admission_periods p JOIN period_school_links l ON l.period_id=p.id
        JOIN master_schools s ON s.id=l.school_id WHERE p.id=?');
    $statement->execute([$periodId]);
    $period = $statement->fetch() ?: throw new AdmissionProblem('Periode tidak ditemukan.', 404);
    $config = admissionData($period['config_json']);
    unset($config['operational']);
    return ['period_id' => $periodId, 'school_id' => $period['school_id'], 'configuration' => $config,
        'school' => array_intersect_key($period, array_flip(['npsn','name','mode','province','city','district','address']))];
}

function operationalSourceHash(array $source): string
{
    return hash('sha256', admissionJson($source));
}

function canReviewOperational(PDO $db, array $user, string $schoolId): bool
{
    $statement = $db->prepare('SELECT 1 FROM staff_accounts s JOIN staff_schools ss ON ss.user_id=s.user_id
        WHERE s.user_id=? AND s.enabled=1 AND s.can_approve=1 AND ss.school_id=?');
    $statement->execute([$user['id'], $schoolId]);
    return isStaff($user) && (bool) $statement->fetchColumn();
}

function operationalPack(PDO $db, string $id, array $user): array
{
    $statement = $db->prepare('SELECT o.*,p.school,p.code,p.academic_year FROM operational_rule_packs o
        JOIN admission_periods p ON p.id=o.period_id WHERE o.id=?');
    $statement->execute([$id]);
    $pack = $statement->fetch();
    if (!$pack || ($user['role'] !== 'central_admin' && !canReviewOperational($db, $user, $pack['school_id']))) {
        throw new AdmissionProblem('Paket aturan tidak ditemukan atau di luar akses approver Anda.', 404);
    }
    return $pack;
}

function validateOperationalPayload(array $values, array $source, array $year): array
{
    if (!$year['starts_on'] || !$year['ends_on']) {
        throw new AdmissionProblem('Lengkapi tanggal pada master tahun ajaran dahulu.', 422);
    }
    if ($year['label'] !== $source['configuration']['academic_year']) {
        throw new AdmissionProblem('Tahun ajaran paket harus sama dengan periode.', 422);
    }
    if (!in_array($values['timezone'] ?? '', ['Asia/Jakarta','Asia/Makassar','Asia/Jayapura'], true)
        || $values['timezone'] !== $source['configuration']['timezone']) {
        throw new AdmissionProblem('Zona waktu harus sama dengan periode.', 422);
    }
    $capacity = operationalInteger($values['capacity'] ?? null, 'Daya tampung', 1);
    $classLimit = operationalInteger($values['class_limit'] ?? null, 'Batas kursi per rombel dari Juknis', 1, 1000);
    $rawClasses = $values['classes'] ?? null;
    if (!is_array($rawClasses) || !$rawClasses || count($rawClasses) > 100) {
        throw new AdmissionProblem('Masukkan 1–100 rombel.', 422);
    }
    $classes = [];
    $names = [];
    $total = 0;
    foreach ($rawClasses as $row) {
        if (!is_array($row)) {
            throw new AdmissionProblem('Isian rombel tidak valid.', 422);
        }
        $name = operationalText($row['name'] ?? null, 'Nama rombel', 100);
        $key = mb_strtolower($name);
        if (isset($names[$key])) {
            throw new AdmissionProblem('Nama rombel tidak boleh duplikat.', 422);
        }
        $names[$key] = true;
        $seats = operationalInteger($row['seats'] ?? null, 'Kursi rombel', 1, $classLimit);
        $classes[] = ['name' => $name, 'seats' => $seats];
        $total += $seats;
    }
    if ($total !== $capacity) {
        throw new AdmissionProblem("Total kursi rombel ($total) harus sama dengan daya tampung ($capacity).", 422);
    }
    $quotaInput = $values['quotas'] ?? null;
    $pathways = $source['configuration']['pathways'];
    $codes = array_column($pathways, 'code');
    if (!is_array($quotaInput) || count($quotaInput) !== count($codes) || array_diff(array_keys($quotaInput), $codes)) {
        throw new AdmissionProblem('Kuota harus memuat tepat semua jalur periode tanpa kode tambahan.', 422);
    }
    $quotas = [];
    foreach ($pathways as $route) {
        $quotas[$route['code']] = operationalInteger($quotaInput[$route['code']] ?? null, 'Kuota ' . $route['name'], 0, $capacity);
    }
    if (array_sum($quotas) !== $capacity) {
        throw new AdmissionProblem('Total kuota jalur harus sama dengan daya tampung; alokasikan seluruh kursi secara eksplisit.', 422);
    }
    if ($source['school']['mode'] === 'public_spmb') {
        $level = $source['configuration']['level'];
        $minimums = match ($level) {
            'SD' => ['domisili' => 70,'afirmasi' => 15],
            'SMP' => ['domisili' => 40,'afirmasi' => 20,'prestasi' => 25],
            'SMA' => ['domisili' => 30,'afirmasi' => 30,'prestasi' => 30],
            default => throw new AdmissionProblem('Jenjang tidak didukung.', 422),
        };
        foreach ($minimums as $code => $percent) {
            if (($quotas[$code] ?? 0) * 100 < $capacity * $percent) {
                throw new AdmissionProblem("Kuota $code minimal $percent% kapasitas untuk $level negeri (baseline Permendikdasmen 3/2025); periksa Juknis.", 422);
            }
        }
        if (($quotas['mutasi'] ?? 0) * 100 > $capacity * 5 || ($level === 'SD' && isset($quotas['prestasi']))) {
            throw new AdmissionProblem('Mutasi maksimal 5%; jalur prestasi tidak berlaku untuk SD.', 422);
        }
    }
    $scheduleInput = $values['schedule'] ?? null;
    if (!is_array($scheduleInput) || count($scheduleInput) !== count(operationalStages())) {
        throw new AdmissionProblem('Lengkapi tepat tujuh tahap jadwal.', 422);
    }
    $schedule = [];
    $timestamps = [];
    $zone = $values['timezone'];
    foreach (operationalStages() as $code => $label) {
        $row = $scheduleInput[$code] ?? null;
        if (!is_array($row)) {
            throw new AdmissionProblem("Jadwal $label belum lengkap.", 422);
        }
        $start = operationalDate($row['start'] ?? null, "Awal $label", 'Y-m-d H:i:s', $zone);
        $end = operationalDate($row['end'] ?? null, "Akhir $label", 'Y-m-d H:i:s', $zone);
        if ($start >= $end) {
            throw new AdmissionProblem("Awal $label harus sebelum akhir tahap.", 422);
        }
        // Admissions may begin before the academic year starts, but not before its calendar year.
        $earliest = operationalDate(substr($year['label'], 0, 4) . '-01-01', 'Awal kalender', 'Y-m-d', $zone);
        $latest = operationalDate($year['ends_on'], 'Akhir tahun ajaran', 'Y-m-d', $zone)->modify('+1 day');
        if ($start < $earliest || $end >= $latest) {
            throw new AdmissionProblem("Jadwal $label harus dalam tahun kalender awal sampai akhir tahun ajaran.", 422);
        }
        $schedule[$code] = ['start' => $row['start'], 'end' => $row['end']];
        $timestamps[$code] = [$start->getTimestamp(), $end->getTimestamp()];
    }
    if ($schedule['registration']['start'] !== $source['configuration']['opens_at']
        || $schedule['registration']['end'] !== $source['configuration']['closes_at']) {
        throw new AdmissionProblem('Jadwal pendaftaran harus sama dengan pembukaan/penutupan periode. Edit periode sebelum membuat paket.', 422);
    }
    if ($timestamps['verification'][0] < $timestamps['registration'][0]
        || $timestamps['verification'][1] < $timestamps['registration'][1]
        || $timestamps['correction'][0] < $timestamps['verification'][0]
        || $timestamps['correction'][1] > $timestamps['verification'][1]) {
        throw new AdmissionProblem('Verifikasi dimulai setelah/saat pendaftaran dibuka dan berakhir setelah/saat pendaftaran ditutup. Perbaikan berada dalam rentang verifikasi.', 422);
    }
    if ($timestamps['selection'][0] < max($timestamps['registration'][1], $timestamps['verification'][1], $timestamps['correction'][1])) {
        throw new AdmissionProblem('Seleksi baru dimulai setelah pendaftaran, verifikasi dan perbaikan ditutup.', 422);
    }
    foreach (['selection' => 'announcement', 'announcement' => 'appeal', 'appeal' => 'reenrollment'] as $before => $after) {
        if ($timestamps[$after][0] < $timestamps[$before][1]) {
            throw new AdmissionProblem('Pengumuman, sanggah dan daftar ulang harus berurutan setelah seleksi.', 422);
        }
    }
    $juknisInput = $values['juknis'] ?? null;
    if (!is_array($juknisInput)) {
        throw new AdmissionProblem('Lengkapi metadata Juknis.', 422);
    }
    $juknis = [];
    foreach (['number' => 'Nomor', 'issuer' => 'Penerbit', 'version' => 'Versi', 'url' => 'Tautan naskah', 'notes' => 'Ketentuan pembulatan / kursi sisa / prioritas'] as $key => $label) {
        $juknis[$key] = operationalText($juknisInput[$key] ?? null, $label . ' Juknis', $key === 'notes' ? 4000 : 500);
    }
    $url = parse_url($juknis['url']);
    if (!filter_var($juknis['url'], FILTER_VALIDATE_URL) || !$url || ($url['scheme'] ?? '') !== 'https'
        || isset($url['user']) || isset($url['pass'])) {
        throw new AdmissionProblem('Tautan naskah Juknis harus URL HTTPS tanpa kredensial.', 422);
    }
    foreach (['date' => 'Tanggal terbit', 'effective_from' => 'Awal berlaku', 'effective_until' => 'Akhir berlaku'] as $key => $label) {
        $juknis[$key] = operationalDate($juknisInput[$key] ?? null, $label . ' Juknis')->format('Y-m-d');
    }
    if ($juknis['date'] > $juknis['effective_from'] || $juknis['effective_from'] > $juknis['effective_until']
        || $juknis['effective_from'] > substr($schedule['registration']['start'], 0, 10)
        || $juknis['effective_until'] < substr($schedule['reenrollment']['end'], 0, 10)) {
        throw new AdmissionProblem('Masa berlaku Juknis harus mencakup pendaftaran sampai daftar ulang dan tidak mendahului tanggal terbit.', 422);
    }
    return ['year' => ['id' => $year['id'], 'label' => $year['label'], 'starts_on' => $year['starts_on'], 'ends_on' => $year['ends_on']],
        'timezone' => $zone, 'capacity' => $capacity, 'class_limit' => $classLimit, 'classes' => $classes,
        'quotas' => $quotas, 'schedule' => $schedule, 'juknis' => $juknis];
}

function operationalEvent(PDO $db, string $id, int $actor, string $action, string $note): void
{
    $db->prepare('INSERT INTO operational_rule_events(pack_id,actor_id,action,note,created_at) VALUES(?,?,?,?,?)')
        ->execute([$id, $actor, $action, $note, time()]);
    audit($db, 'admin.rule_pack_' . $action . ':' . $id, $actor);
}

function saveOperationalPack(PDO $db, int $actor, ?string $id, ?int $version, string $periodId, string $yearId, array $values, string $reason): string
{
    return admissionTransaction($db, function () use ($db, $actor, $id, $version, $periodId, $yearId, $values, $reason): string {
        requireCentral($db, $actor);
        $usage = $db->prepare('SELECT used FROM period_management WHERE period_id=?');
        $usage->execute([$periodId]);
        if ((int) $usage->fetchColumn()) {
            throw new AdmissionProblem('Periode pernah dipakai. Salin menjadi periode baru sebelum menyusun paket aturan.', 409);
        }
        $reason = operationalText($reason, 'Alasan pembuatan/perubahan');
        $source = operationalSource($db, $periodId);
        $year = academicYear($db, $yearId);
        if (!(int) $year['enabled']) {
            throw new AdmissionProblem('Tahun ajaran diarsipkan.', 409);
        }
        $payload = validateOperationalPayload($values, $source, $year);
        if ($id) {
            $pack = operationalPack($db, $id, staffIdentity($db, $actor));
            if ((int) $pack['version'] !== $version || !in_array($pack['status'], ['draft','returned'], true)) {
                throw new AdmissionProblem('Paket sudah berubah atau dibekukan untuk tinjauan. Muat ulang/buat versi baru.', 409);
            }
            if ($pack['period_id'] !== $periodId) {
                throw new AdmissionProblem('Periode paket tidak dapat diganti.', 422);
            }
            $db->prepare("UPDATE operational_rule_packs SET school_id=?,year_id=?,payload_json=?,source_json=?,source_hash=?,reason=?,status='draft',
                payload_hash=NULL,approved_by=NULL,submitted_by=NULL,version=version+1,updated_at=? WHERE id=?")
                ->execute([$source['school_id'], $yearId, admissionJson($payload), admissionJson($source), operationalSourceHash($source), $reason, time(), $id]);
        } else {
            $statement = $db->prepare("SELECT 1 FROM operational_rule_packs WHERE period_id=? AND status IN ('draft','pending','returned','approved')");
            $statement->execute([$periodId]);
            if ($statement->fetchColumn()) {
                throw new AdmissionProblem('Periode masih memiliki versi kerja. Selesaikan versi tersebut dahulu.', 409);
            }
            $statement = $db->prepare('SELECT COALESCE(MAX(revision),0)+1 FROM operational_rule_packs WHERE period_id=?');
            $statement->execute([$periodId]);
            $revision = (int) $statement->fetchColumn();
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO operational_rule_packs(id,period_id,school_id,year_id,revision,payload_json,source_json,source_hash,reason,created_by,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $periodId, $source['school_id'], $yearId, $revision, admissionJson($payload),
                    admissionJson($source), operationalSourceHash($source), $reason, $actor, time(), time()]);
            // First migration into approval-managed rules closes admission writes until publication.
            if ($revision === 1) {
                $db->prepare('INSERT INTO admission_period_availability(period_id,enabled) VALUES(?,0)
                    ON CONFLICT(period_id) DO UPDATE SET enabled=0')->execute([$periodId]);
                $db->prepare('UPDATE period_management SET version=version+1 WHERE period_id=?')->execute([$periodId]);
            }
        }
        operationalEvent($db, $id, $actor, 'saved', $reason);
        return $id;
    });
}

function operationalPackAction(PDO $db, array $actor, string $id, int $version, string $action, string $note): void
{
    admissionTransaction($db, function () use ($db, $actor, $id, $version, $action, $note): void {
        $actor = staffIdentity($db, (int) $actor['id']);
        $pack = operationalPack($db, $id, $actor);
        if ((int) $pack['version'] !== $version) {
            throw new AdmissionProblem('Paket telah berubah oleh pengguna lain. Muat ulang sebelum melanjutkan.', 409);
        }
        $note = operationalText($note, 'Catatan keputusan');
        if (in_array($action, ['submit','resubmit','publish'], true)) {
            requireCentral($db, (int) $actor['id']);
        } elseif (in_array($action, ['approve','return'], true)) {
            if (!canReviewOperational($db, $actor, $pack['school_id'])
                || (int) $actor['id'] === (int) $pack['created_by'] || (int) $actor['id'] === (int) $pack['submitted_by']) {
                throw new AdmissionProblem('Persetujuan hanya oleh approver sekolah aktif yang berbeda dari penyusun/pengaju.', 403);
            }
        } else {
            throw new AdmissionProblem('Aksi paket tidak valid.', 422);
        }
        $requiredStatus = match ($action) {
            'submit' => ['draft','returned'], 'resubmit' => ['approved'], 'approve','return' => ['pending'], 'publish' => ['approved'],
        };
        if (!in_array($pack['status'], $requiredStatus, true)) {
            throw new AdmissionProblem('Transisi status paket tidak diizinkan. Muat ulang data.', 409);
        }
        $source = operationalSource($db, $pack['period_id']);
        if ($action !== 'return' && operationalSourceHash($source) !== $pack['source_hash']) {
            throw new AdmissionProblem('Identitas/periode/jalur sumber telah berubah. Kembalikan paket dan simpan ulang sebelum diajukan.', 409);
        }
        $year = academicYear($db, $pack['year_id']);
        if ($action !== 'return' && !(int) $year['enabled']) {
            throw new AdmissionProblem('Tahun ajaran diarsipkan; aktifkan sebelum mengajukan/menyetujui/menerbitkan.', 409);
        }
        $payload = admissionData($pack['payload_json']);
        if ($action !== 'return') {
            validateOperationalPayload($payload, $source, $year);
        }
        $hash = hash('sha256', $pack['source_json'] . "\n" . $pack['payload_json']);
        if (!in_array($action, ['submit','return'], true) && !hash_equals($pack['payload_hash'] ?? '', $hash)) {
            throw new RuntimeException('Integritas paket persetujuan gagal.');
        }
        if ($action === 'submit' || $action === 'resubmit') {
            $db->prepare("UPDATE operational_rule_packs SET status='pending',submitted_by=?,approved_by=NULL,payload_hash=?,version=version+1,updated_at=? WHERE id=?")
                ->execute([$actor['id'], $hash, time(), $id]);
        } elseif ($action === 'approve' || $action === 'return') {
            $db->prepare('UPDATE operational_rule_packs SET status=?,approved_by=?,version=version+1,updated_at=? WHERE id=?')
                ->execute([$action === 'approve' ? 'approved' : 'returned', $action === 'approve' ? $actor['id'] : null, time(), $id]);
        } else {
            $statement = $db->prepare('SELECT used FROM period_management WHERE period_id=?');
            $statement->execute([$pack['period_id']]);
            if ((int) $statement->fetchColumn()) {
                throw new AdmissionProblem('Periode pernah dipakai pendaftaran. Paket tidak dapat mengganti aturan; salin menjadi periode baru.', 409);
            }
            $statement = $db->prepare('SELECT enabled FROM master_schools WHERE id=?');
            $statement->execute([$pack['school_id']]);
            if (!(int) $statement->fetchColumn() || !canReviewOperational($db, ['id' => $pack['approved_by'], 'role' => 'school_admin'], $pack['school_id'])) {
                throw new AdmissionProblem('Sekolah/approver tidak aktif atau grant approver telah dicabut. Penerbitan ditolak.', 409);
            }
            $config = $source['configuration'];
            $config['operational'] = ['pack_id' => $id, 'revision' => (int) $pack['revision'], 'hash' => $hash, 'data' => $payload];
            $db->prepare("UPDATE operational_rule_packs SET status='superseded',version=version+1,updated_at=? WHERE period_id=? AND status='published'")
                ->execute([time(), $pack['period_id']]);
            $db->prepare("UPDATE operational_rule_packs SET status='published',version=version+1,updated_at=? WHERE id=?")->execute([time(), $id]);
            $db->prepare('UPDATE admission_periods SET config_json=? WHERE id=?')->execute([admissionJson($config), $pack['period_id']]);
            $db->prepare('INSERT INTO admission_period_availability(period_id,enabled) VALUES(?,0)
                ON CONFLICT(period_id) DO UPDATE SET enabled=0')->execute([$pack['period_id']]);
            $db->prepare('UPDATE period_management SET version=version+1 WHERE period_id=?')->execute([$pack['period_id']]);
        }
        operationalEvent($db, $id, (int) $actor['id'], $action, $note);
    });
}

function operationalPeriodReady(PDO $db, string $periodId): bool
{
    $statement = $db->prepare('SELECT * FROM operational_rule_packs WHERE period_id=? ORDER BY revision DESC');
    $statement->execute([$periodId]);
    $packs = $statement->fetchAll();
    if (!$packs) {
        return true; // Legacy pilot periods retain their existing development behavior.
    }
    foreach ($packs as $pack) {
        if ($pack['status'] === 'published') {
            $period = $db->prepare('SELECT config_json FROM admission_periods WHERE id=?');
            $period->execute([$periodId]);
            $config = admissionData($period->fetchColumn());
            $expected = ['pack_id' => $pack['id'], 'revision' => (int) $pack['revision'],
                'hash' => hash('sha256', $pack['source_json'] . "\n" . $pack['payload_json']), 'data' => admissionData($pack['payload_json'])];
            $valid = hash_equals($pack['source_hash'], operationalSourceHash(operationalSource($db, $periodId)))
                && hash_equals($pack['payload_hash'] ?? '', $expected['hash'])
                && ($config['operational'] ?? null) === $expected;
            if (!$valid) {
                error_log('Published operational rule integrity mismatch for period ' . $periodId);
            }
            return $valid;
        }
    }
    return false;
}

function assertOperationalPeriodEditable(PDO $db, string $periodId): void
{
    $statement = $db->prepare("SELECT 1 FROM operational_rule_packs WHERE period_id=? AND status IN ('pending','approved','published','superseded')");
    $statement->execute([$periodId]);
    if ($statement->fetchColumn()) {
        throw new AdmissionProblem('Paket aturan dibekukan/diterbitkan. Periode dan identitas sumber tidak dapat diedit; gunakan periode baru.', 409);
    }
}
