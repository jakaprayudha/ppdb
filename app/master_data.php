<?php
declare(strict_types=1);

function masterSchool(PDO $db, string $id): array
{
    $statement = $db->prepare('SELECT s.*, EXISTS(SELECT 1 FROM period_school_links l JOIN period_management m
        ON m.period_id = l.period_id WHERE l.school_id = s.id AND m.used = 1) AS used,
        (SELECT COUNT(*) FROM period_school_links l WHERE l.school_id = s.id) AS periods
        FROM master_schools s WHERE s.id = ?');
    $statement->execute([$id]);
    $school = $statement->fetch();
    if (!$school) {
        throw new AdmissionProblem('Sekolah tidak ditemukan.', 404);
    }
    return $school;
}

function masterPeriod(PDO $db, string $id): array
{
    $period = admissionPeriod($db, $id);
    $statement = $db->prepare('SELECT l.school_id, m.version AS management_version, m.used
        FROM period_school_links l JOIN period_management m ON m.period_id = l.period_id WHERE l.period_id = ?');
    $statement->execute([$id]);
    $management = $statement->fetch();
    if (!$management) {
        throw new RuntimeException('Metadata master periode tidak tersedia.');
    }
    return $period + $management;
}

function masterVersion(): int
{
    $version = filter_var(input('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($version === false) {
        throw new AdmissionProblem('Versi data tidak valid. Muat ulang halaman.', 409);
    }
    return $version;
}

function validateMasterSchool(array $data): array
{
    foreach (['name' => 200, 'province' => 100, 'city' => 100, 'district' => 100, 'address' => 500] as $key => $maximum) {
        $data[$key] = trim($data[$key] ?? '');
        if ($data[$key] === '' || mb_strlen($data[$key]) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $data[$key])) {
            throw new AdmissionProblem('Lengkapi nama, provinsi, kabupaten/kota, kecamatan, dan alamat sekolah dengan panjang yang valid.', 422);
        }
    }
    if (!preg_match('/\A[0-9]{8}\z/', $data['npsn'] ?? '') || !in_array($data['level'] ?? '', ['SD', 'SMP', 'SMA'], true)
        || !in_array($data['mode'] ?? '', ['public_spmb', 'private_independent'], true)) {
        throw new AdmissionProblem('NPSN harus 8 digit; pilih jenjang dan mode penerimaan yang valid.', 422);
    }
    return $data;
}

function saveMasterSchool(PDO $db, ?string $id, int $actor, array $data, ?int $version): string
{
    $data = validateMasterSchool($data);
    return admissionTransaction($db, function () use ($db, $id, $actor, $data, $version): string {
        requireCentral($db, $actor);
        if ($id) {
            $school = masterSchool($db, $id);
            if ((int) $school['version'] !== $version) {
                throw new AdmissionProblem('Sekolah telah berubah. Muat ulang halaman.', 409);
            }
            if ((int) $school['used']) {
                throw new AdmissionProblem('Sekolah sudah dipakai pendaftaran. Identitas tidak dapat ditimpa; hanya dapat diarsipkan.', 409);
            }
        }
        $duplicate = $db->prepare('SELECT id FROM master_schools WHERE npsn = ? AND id != ?');
        $duplicate->execute([$data['npsn'], $id ?? '']);
        if ($duplicate->fetch()) {
            throw new AdmissionProblem('NPSN sudah terdaftar.', 422);
        }
        if ($id && (int) $school['periods'] > 0 && ($school['level'] !== $data['level'] || $school['mode'] !== $data['mode'])) {
            throw new AdmissionProblem('Jenjang/mode sekolah yang memiliki periode tidak dapat diganti. Hapus periode yang belum dipakai dahulu.', 409);
        }
        $values = array_map(fn(string $key): string => $data[$key], ['npsn', 'name', 'level', 'mode', 'province', 'city', 'district', 'address']);
        if ($id) {
            $db->prepare('UPDATE master_schools SET npsn=?, name=?, level=?, mode=?, province=?, city=?, district=?, address=?, version=version+1 WHERE id=?')
                ->execute([...$values, $id]);
            $statement = $db->prepare('SELECT p.* FROM admission_periods p JOIN period_school_links l ON l.period_id=p.id WHERE l.school_id=?');
            $statement->execute([$id]);
            foreach ($statement->fetchAll() as $period) {
                assertOperationalPeriodEditable($db, $period['id']);
                $rules = admissionData($period['config_json']);
                $rules = array_replace($rules, ['school' => $data['name'], 'npsn' => $data['npsn'], 'province' => $data['province'],
                    'regency' => $data['city'], 'district' => $data['district']]);
                unset($rules['school_source'], $rules['school_verified_at']);
                $db->prepare('UPDATE admission_periods SET school=?, config_json=? WHERE id=?')
                    ->execute([$data['name'], admissionJson($rules), $period['id']]);
                $db->prepare('UPDATE period_management SET version=version+1 WHERE period_id=?')->execute([$period['id']]);
            }
        } else {
            $id = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO master_schools(npsn,name,level,mode,province,city,district,address,id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([...$values, $id]);
        }
        audit($db, 'admin.school_saved', $actor);
        return $id;
    });
}

function saveMasterPeriod(PDO $db, ?string $id, int $actor, string $schoolId, array $rules, ?int $version): string
{
    return admissionTransaction($db, function () use ($db, $id, $actor, $schoolId, $rules, $version): string {
        requireCentral($db, $actor);
        $school = masterSchool($db, $schoolId);
        if (!(int) $school['enabled']) {
            throw new AdmissionProblem('Sekolah diarsipkan. Aktifkan sekolah sebelum membuat/mengedit periode.', 409);
        }
        if ($id) {
            assertOperationalPeriodEditable($db, $id);
            $current = masterPeriod($db, $id);
            if ((int) $current['management_version'] !== $version) {
                throw new AdmissionProblem('Periode telah berubah. Muat ulang halaman.', 409);
            }
            if ((int) $current['used']) {
                throw new AdmissionProblem('Periode sudah dipakai pendaftaran. Arsipkan atau salin menjadi periode baru.', 409);
            }
        }
        $rules = array_replace($rules, ['school' => $school['name'], 'level' => $school['level'],
            'npsn' => $school['npsn'] ?? '', 'province' => $school['province'], 'regency' => $school['city'],
            'district' => $school['district'], 'is_demo' => $id ? (bool) $current['is_demo'] : true]);
        unset($rules['school_source'], $rules['school_verified_at']);
        try {
            $rules = validatePeriodConfiguration($rules);
        } catch (InvalidArgumentException $exception) {
            throw new AdmissionProblem($exception->getMessage(), 422);
        }
        if (($rules['admission_mode'] ?? '') !== $school['mode']) {
            throw new AdmissionProblem('Mode periode harus sesuai mode sekolah.', 422);
        }
        $duplicate = $db->prepare('SELECT id FROM admission_periods WHERE code=? AND id != ?');
        $duplicate->execute([$rules['code'], $id ?? '']);
        if ($duplicate->fetch()) {
            throw new AdmissionProblem('Kode periode sudah digunakan. Gunakan kode baru.', 422);
        }
        if ($id) {
            $db->prepare('UPDATE admission_periods SET code=?,organizer=?,school=?,level=?,academic_year=?,opens_at=?,closes_at=?,timezone=?,is_demo=?,config_json=? WHERE id=?')
                ->execute([$rules['code'], $rules['organizer'], $rules['school'], $rules['level'], $rules['academic_year'],
                    $rules['opens_at_timestamp'], $rules['closes_at_timestamp'], $rules['timezone'], (int) $rules['is_demo'], admissionJson($rules), $id]);
            $db->prepare('UPDATE period_school_links SET school_id=? WHERE period_id=?')->execute([$schoolId, $id]);
            $db->prepare('UPDATE period_management SET version=version+1 WHERE period_id=?')->execute([$id]);
        } else {
            $id = persistAdmissionPeriod($db, $rules);
            $db->prepare('INSERT INTO period_school_links(period_id,school_id) VALUES (?,?)')->execute([$id, $schoolId]);
            $db->prepare('INSERT INTO period_management(period_id) VALUES (?)')->execute([$id]);
            $db->prepare('INSERT INTO admission_period_availability(period_id,enabled) VALUES (?,0)')->execute([$id]);
        }
        audit($db, 'admin.period_saved', $actor);
        return $id;
    });
}

function masterAction(PDO $db, string $type, string $id, int $actor, int $version, string $action): void
{
    admissionTransaction($db, function () use ($db, $type, $id, $actor, $version, $action): void {
        requireCentral($db, $actor);
        $item = $type === 'schools' ? masterSchool($db, $id) : masterPeriod($db, $id);
        if ((int) $item[$type === 'schools' ? 'version' : 'management_version'] !== $version) {
            throw new AdmissionProblem('Data telah berubah. Muat ulang sebelum melanjutkan.', 409);
        }
        if (!in_array($action, ['delete', 'archive', 'activate'], true)) {
            throw new AdmissionProblem('Aksi master data tidak valid.', 422);
        }
        if ($action === 'delete') {
            if ((int) $item['used'] || ($type === 'schools' && (int) $item['periods'] > 0)) {
                throw new AdmissionProblem('Data sudah dipakai atau masih memiliki periode. Penghapusan ditolak; gunakan arsip.', 409);
            }
            if (input('confirm_delete') !== '1') {
                throw new AdmissionProblem('Konfirmasi penghapusan diperlukan.', 422);
            }
            if ($type === 'schools') {
                $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id IN
                    (SELECT user_id FROM staff_schools WHERE school_id=?)')->execute([$id]);
                $db->prepare('UPDATE staff_accounts SET version=version+1 WHERE user_id IN
                    (SELECT user_id FROM staff_schools WHERE school_id=?)')->execute([$id]);
                $db->prepare('UPDATE staff_invitations SET cancelled_at=? WHERE used_at IS NULL AND cancelled_at IS NULL
                    AND id IN (SELECT invitation_id FROM invitation_schools WHERE school_id=?)')->execute([time(), $id]);
                $db->prepare('DELETE FROM master_schools WHERE id=?')->execute([$id]);
            } else {
                $statement = $db->prepare('SELECT 1 FROM operational_rule_packs WHERE period_id=?');
                $statement->execute([$id]);
                if ($statement->fetchColumn()) {
                    throw new AdmissionProblem('Periode memiliki riwayat paket aturan. Gunakan arsip; paket tidak dihapus.', 409);
                }
                foreach (['period_school_links', 'period_management', 'admission_period_availability'] as $table) {
                    $db->prepare("DELETE FROM $table WHERE period_id=?")->execute([$id]);
                }
                $db->prepare('DELETE FROM admission_periods WHERE id=?')->execute([$id]);
            }
        } elseif ($type === 'schools') {
            $db->prepare('UPDATE master_schools SET enabled=?, version=version+1 WHERE id=?')->execute([(int) ($action === 'activate'), $id]);
            if ($action === 'archive') {
                $db->prepare('INSERT INTO admission_period_availability(period_id,enabled)
                    SELECT period_id,0 FROM period_school_links WHERE school_id=? ON CONFLICT(period_id) DO UPDATE SET enabled=0')->execute([$id]);
                $db->prepare('UPDATE period_management SET version=version+1 WHERE period_id IN (SELECT period_id FROM period_school_links WHERE school_id=?)')->execute([$id]);
            }
        } else {
            if ($action === 'activate' && !operationalPeriodReady($db, $id)) {
                throw new AdmissionProblem('Terbitkan paket aturan yang disetujui terlebih dahulu sebelum mengaktifkan periode.', 409);
            }
            if ($action === 'activate' && !(int) masterSchool($db, $item['school_id'])['enabled']) {
                throw new AdmissionProblem('Aktifkan sekolah terlebih dahulu.', 409);
            }
            $db->prepare('INSERT INTO admission_period_availability(period_id,enabled) VALUES (?,?)
                ON CONFLICT(period_id) DO UPDATE SET enabled=excluded.enabled')->execute([$id, (int) ($action === 'activate')]);
            $db->prepare('UPDATE period_management SET version=version+1 WHERE period_id=?')->execute([$id]);
        }
        audit($db, 'admin.' . $type . '_' . $action, $actor);
    });
}
