<?php
declare(strict_types=1);

final class AdmissionProblem extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}

function admissionJson(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function admissionData(string $json): array
{
    $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Data penerimaan tidak valid.');
    }
    return $data;
}

function admissionTransaction(PDO $db, callable $callback): mixed
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $callback();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function admissionFields(): array
{
    return [
        'name' => ['Nama lengkap peserta', 100],
        'nisn' => ['NISN', 10],
        'sex' => ['Jenis kelamin', 1],
        'birth_place' => ['Tempat lahir', 100],
        'birth_date' => ['Tanggal lahir', 10],
        'source_school' => ['Sekolah asal', 150],
        'guardian_name' => ['Nama orang tua / wali', 100],
        'relationship' => ['Hubungan dengan peserta', 20],
        'phone' => ['Nomor telepon wali', 20],
        'address' => ['Alamat lengkap', 500],
        'province' => ['Provinsi', 100],
        'city' => ['Kabupaten / kota', 100],
        'district' => ['Kecamatan', 100],
        'village' => ['Desa / kelurahan', 100],
        'postal_code' => ['Kode pos', 5],
    ];
}

function participantInput(): array
{
    $data = [];
    foreach (admissionFields() as $key => $_) {
        $data[$key] = trim(input($key));
    }
    return $data;
}

function validateParticipant(array $data, bool $complete = false, string $level = 'SD', bool $profile = false): array
{
    $errors = [];
    $required = ['name', 'sex', 'birth_place', 'birth_date', 'guardian_name', 'relationship', 'phone',
        'address', 'province', 'city', 'district', 'village'];
    if ($level !== 'SD') {
        $required[] = 'source_school';
    }
    foreach (admissionFields() as $field => [$label, $maximum]) {
        $value = $data[$field] ?? '';
        if (!is_string($value) || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            $errors[$field] = "$label tidak valid atau terlalu panjang (maksimal $maximum karakter).";
        } elseif (($complete && in_array($field, $required, true) || $profile && $field === 'name') && $value === '') {
            $errors[$field] = "$label wajib diisi.";
        }
    }
    if (($data['name'] ?? '') !== '' && mb_strlen($data['name']) < 2) {
        $errors['name'] = 'Nama peserta minimal 2 karakter.';
    }
    if (($data['nisn'] ?? '') !== '' && !preg_match('/\A[0-9]{10}\z/', $data['nisn'])) {
        $errors['nisn'] = 'NISN harus 10 digit, atau kosongkan jika belum tersedia.';
    }
    if (($data['sex'] ?? '') !== '' && !in_array($data['sex'], ['L', 'P'], true)) {
        $errors['sex'] = 'Pilih jenis kelamin yang tersedia.';
    }
    if (($data['relationship'] ?? '') !== '' && !in_array($data['relationship'], ['Ayah', 'Ibu', 'Wali'], true)) {
        $errors['relationship'] = 'Pilih hubungan orang tua / wali.';
    }
    if (($data['phone'] ?? '') !== '' && !preg_match('/\A(?:\+62|0)[0-9]{8,13}\z/', $data['phone'])) {
        $errors['phone'] = 'Gunakan nomor telepon tanpa spasi, dimulai 0 atau +62.';
    }
    if (($data['postal_code'] ?? '') !== '' && !preg_match('/\A[0-9]{5}\z/', $data['postal_code'])) {
        $errors['postal_code'] = 'Kode pos harus 5 digit.';
    }
    if (($data['birth_date'] ?? '') !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['birth_date'], new DateTimeZone('Asia/Jakarta'));
        if (!$date || $date->format('Y-m-d') !== $data['birth_date'] || $date > new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'))
            || $date->format('Y') < '1900') {
            $errors['birth_date'] = 'Tanggal lahir harus tanggal yang valid dan tidak di masa depan.';
        }
    }
    return $errors;
}

function ownedProfile(PDO $db, string $id, int $userId): array
{
    $statement = $db->prepare('SELECT * FROM participant_profiles WHERE id = ? AND user_id = ?');
    $statement->execute([$id, $userId]);
    $profile = $statement->fetch();
    if (!$profile) {
        throw new AdmissionProblem('Profil peserta tidak ditemukan.', 404);
    }
    return $profile;
}

function admissionPeriod(PDO $db, string $id): array
{
    $statement = $db->prepare('SELECT p.*, MIN(COALESCE(v.enabled, 1), COALESCE(s.enabled, 1)) AS enabled FROM admission_periods p
        LEFT JOIN admission_period_availability v ON v.period_id = p.id
        LEFT JOIN period_school_links l ON l.period_id = p.id LEFT JOIN master_schools s ON s.id = l.school_id WHERE p.id = ?');
    $statement->execute([$id]);
    $period = $statement->fetch();
    if (!$period) {
        throw new AdmissionProblem('Periode penerimaan tidak ditemukan.', 404);
    }
    $period['configuration'] = admissionData($period['config_json']);
    if (!operationalPeriodReady($db, $id)) {
        $period['enabled'] = 0;
    }
    return $period;
}

function periodState(array $period): string
{
    if (isset($period['enabled']) && !(int) $period['enabled']) {
        return 'Diarsipkan';
    }
    return time() < (int) $period['opens_at'] ? 'Belum dibuka'
        : (time() >= (int) $period['closes_at'] ? 'Ditutup' : 'Pendaftaran dibuka');
}

function assertPeriodOpen(array $period): void
{
    if (isset($period['enabled']) && !(int) $period['enabled']) {
        throw new AdmissionProblem('Periode diarsipkan dan tidak menerima perubahan atau pendaftaran baru. Data lama tetap dapat dibaca.', 409);
    }
    if (time() < (int) $period['opens_at'] || time() >= (int) $period['closes_at']) {
        throw new AdmissionProblem('Periode tidak sedang dibuka. Draf tetap tersimpan dan dapat dibaca.', 409);
    }
}

function ownedApplication(PDO $db, string $id, int $userId): array
{
    $statement = $db->prepare('SELECT * FROM applications WHERE id = ? AND user_id = ?');
    $statement->execute([$id, $userId]);
    $application = $statement->fetch();
    if (!$application) {
        throw new AdmissionProblem('Pendaftaran tidak ditemukan.', 404);
    }
    return $application;
}

function assertDraft(array $application, int $version): void
{
    if ($application['status'] !== 'draft') {
        throw new AdmissionProblem('Pendaftaran sudah dikirim dan tidak dapat diubah.', 409);
    }
    if ((int) $application['version'] !== $version) {
        throw new AdmissionProblem('Draf telah berubah di tab atau perangkat lain. Muat ulang sebelum menyimpan agar data tidak tertimpa.', 409);
    }
}

function applicationEvent(PDO $db, string $id, int $userId, string $action): void
{
    $db->prepare('INSERT INTO application_events (application_id, actor_id, action, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, $userId, $action, time()]);
    audit($db, 'admission.' . $action, $userId);
}

function applicationDocuments(PDO $db, string $id): array
{
    $statement = $db->prepare('SELECT * FROM application_documents WHERE application_id = ? AND deleted_at IS NULL ORDER BY uploaded_at');
    $statement->execute([$id]);
    $documents = [];
    foreach ($statement->fetchAll() as $document) {
        $documents[$document['kind']] = $document;
    }
    return $documents;
}

function pathwayConfig(array $period, string $code): array
{
    foreach ($period['configuration']['pathways'] as $pathway) {
        if ($pathway['code'] === $code) {
            return $pathway;
        }
    }
    throw new AdmissionProblem('Pilih jalur yang tersedia untuk periode ini.', 422, ['pathway' => 'Jalur tidak tersedia.']);
}

function admissionDate(int $timestamp, string $timezone): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone))->format('d/m/Y H:i') . ' (' . $timezone . ')';
}

function createApplication(PDO $db, int $userId, string $profileId, string $periodId, string $pathway): string
{
    return admissionTransaction($db, function () use ($db, $userId, $profileId, $periodId, $pathway): string {
        $profile = ownedProfile($db, $profileId, $userId);
        $period = admissionPeriod($db, $periodId);
        assertPeriodOpen($period);
        pathwayConfig($period, $pathway);
        $existing = $db->prepare('SELECT id FROM applications WHERE profile_id = ? AND period_id = ?');
        $existing->execute([$profileId, $periodId]);
        if ($id = $existing->fetchColumn()) {
            return $id;
        }
        $id = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO applications (id, user_id, profile_id, period_id, pathway, data_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $userId, $profileId, $periodId, $pathway, $profile['data_json'], time(), time()]);
        applicationEvent($db, $id, $userId, 'draft_created');
        return $id;
    });
}

function saveApplicationStep(PDO $db, string $id, int $userId, int $version, int $step, array $submitted, string $pathway): void
{
    admissionTransaction($db, function () use ($db, $id, $userId, $version, $step, $submitted, $pathway): void {
        $application = ownedApplication($db, $id, $userId);
        assertDraft($application, $version);
        $period = admissionPeriod($db, $application['period_id']);
        assertPeriodOpen($period);
        $data = admissionData($application['data_json']);
        $keys = $step === 1 ? ['name', 'nisn', 'sex', 'birth_place', 'birth_date', 'source_school']
            : ['guardian_name', 'relationship', 'phone', 'address', 'province', 'city', 'district', 'village', 'postal_code'];
        foreach ($keys as $key) {
            $data[$key] = $submitted[$key];
        }
        $errors = validateParticipant($data);
        if ($errors) {
            throw new AdmissionProblem('Periksa kembali data yang diisi. Perubahan belum disimpan.', 422, $errors);
        }
        $selected = $step === 2 ? $pathway : $application['pathway'];
        pathwayConfig($period, $selected);
        $db->prepare('UPDATE applications SET data_json = ?, pathway = ?, version = version + 1, updated_at = ? WHERE id = ?')
            ->execute([admissionJson($data), $selected, time(), $id]);
        applicationEvent($db, $id, $userId, 'draft_saved');
    });
}

function cleanupCancelledDocuments(PDO $db, string $storage): void
{
    // Queue survives crashes between committing cancellation and deleting physical files.
    admissionTransaction($db, function () use ($db, $storage): void {
        $names = $db->query('SELECT storage_name FROM document_deletion_queue')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $name) {
            if (!preg_match('/\A[a-f0-9]{32}\.(pdf|jpg|png)\z/', $name)) {
                throw new RuntimeException('Nama berkas antrean penghapusan tidak valid.');
            }
            $path = $storage . '/documents/' . $name;
            if ((file_exists($path) || is_link($path)) && !unlink($path)) {
                error_log('[PPDB] Cancelled document cleanup failed.');
                throw new AdmissionProblem('Draf sudah dibatalkan, tetapi penghapusan berkas belum selesai. Hubungi pengelola untuk menjalankan pembersihan ulang.', 503);
            }
            $db->prepare('DELETE FROM document_deletion_queue WHERE storage_name = ?')->execute([$name]);
        }
    });
}

function cancelApplication(PDO $db, string $storage, string $id, int $userId, int $version): void
{
    admissionTransaction($db, function () use ($db, $id, $userId, $version): void {
        $application = ownedApplication($db, $id, $userId);
        assertDraft($application, $version);
        $db->prepare('INSERT INTO document_deletion_queue (storage_name, created_at)
            SELECT storage_name, ? FROM application_documents WHERE application_id = ?')
            ->execute([time(), $id]);
        $db->prepare('DELETE FROM application_documents WHERE application_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM application_events WHERE application_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM applications WHERE id = ?')->execute([$id]);
        audit($db, 'admission.draft_cancelled', $userId);
    });
    cleanupCancelledDocuments($db, $storage);
}

function storeApplicationDocument(PDO $db, array $config, string $id, int $userId, int $version, string $kind, array $file): void
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Berkas terlalu besar. Maksimal 2 MB.',
            UPLOAD_ERR_PARTIAL => 'Unggahan terputus. Silakan pilih berkas dan coba lagi.',
            UPLOAD_ERR_NO_FILE => 'Pilih berkas yang akan diunggah.',
            default => 'Unggahan tidak dapat diproses. Silakan coba lagi atau hubungi pengelola.',
        };
        if (!in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE], true)) {
            error_log('[PPDB] Upload gagal dengan kode ' . (is_int($error) ? $error : 'invalid'));
        }
        throw new AdmissionProblem($message);
    }
    $temporary = $file['tmp_name'] ?? null;
    $original = $file['name'] ?? null;
    if (!is_string($temporary) || !is_uploaded_file($temporary) || !is_string($original)) {
        throw new AdmissionProblem('Berkas unggahan tidak valid.');
    }
    $size = filesize($temporary);
    if ($size === false || $size < 1 || $size > 2 * 1024 * 1024) {
        throw new AdmissionProblem('Ukuran berkas harus lebih dari 0 dan maksimal 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset($extensions[$mime]) || !in_array($extension, $mime === 'image/jpeg' ? ['jpg', 'jpeg'] : [$extensions[$mime]], true)) {
        throw new AdmissionProblem('Berkas harus berupa PDF, JPG, atau PNG asli dengan ekstensi yang sesuai.');
    }
    if ($mime === 'application/pdf') {
        $stream = fopen($temporary, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Berkas sementara tidak dapat dibaca.');
        }
        $signature = fread($stream, 5);
        fclose($stream);
        if ($signature !== '%PDF-') {
            throw new AdmissionProblem('Berkas PDF tidak valid.');
        }
    } else {
        $image = getimagesize($temporary);
        if (!$image || $image[0] * $image[1] > 20000000) {
            throw new AdmissionProblem('Gambar tidak valid atau melebihi 20 megapiksel.');
        }
    }
    $name = basename(str_replace('\\', '/', $original));
    if (mb_strlen($name) > 150 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        throw new AdmissionProblem('Nama berkas maksimal 150 karakter tanpa karakter kontrol.');
    }
    $directory = $config['storage'] . '/documents';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Penyimpanan dokumen tidak tersedia.');
    }
    $documentId = bin2hex(random_bytes(16));
    $storageName = $documentId . '.' . $extensions[$mime];
    $destination = $directory . '/' . $storageName;
    try {
        admissionTransaction($db, function () use ($db, $id, $userId, $version, $kind, $temporary, $destination, $documentId, $storageName, $name, $mime, $size): void {
            $application = ownedApplication($db, $id, $userId);
            assertDraft($application, $version);
            $period = admissionPeriod($db, $application['period_id']);
            assertPeriodOpen($period);
            $requirements = pathwayConfig($period, $application['pathway'])['documents'];
            if (!in_array($kind, array_column($requirements, 'code'), true)) {
                throw new AdmissionProblem('Jenis dokumen tidak tersedia untuk jalur ini.');
            }
            $hash = hash_file('sha256', $temporary);
            if ($hash === false || !move_uploaded_file($temporary, $destination)) {
                throw new RuntimeException('Gagal menyimpan berkas unggahan.');
            }
            $db->prepare('UPDATE application_documents SET deleted_at = ? WHERE application_id = ? AND kind = ? AND deleted_at IS NULL')
                ->execute([time(), $id, $kind]);
            $db->prepare('INSERT INTO application_documents (id, application_id, kind, original_name, storage_name, mime_type, size, sha256, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$documentId, $id, $kind, $name, $storageName, $mime, $size, $hash, time()]);
            $db->prepare('UPDATE applications SET version = version + 1, updated_at = ? WHERE id = ?')->execute([time(), $id]);
            applicationEvent($db, $id, $userId, 'document_uploaded');
        });
    } catch (Throwable $exception) {
        if (is_file($destination) && !unlink($destination)) {
            error_log('[PPDB] Berkas gagal dibersihkan setelah rollback: ' . $storageName);
        }
        throw $exception;
    }
}

function removeApplicationDocument(PDO $db, string $id, int $userId, int $version, string $documentId): void
{
    admissionTransaction($db, function () use ($db, $id, $userId, $version, $documentId): void {
        $application = ownedApplication($db, $id, $userId);
        assertDraft($application, $version);
        assertPeriodOpen(admissionPeriod($db, $application['period_id']));
        $statement = $db->prepare('UPDATE application_documents SET deleted_at = ? WHERE id = ? AND application_id = ? AND deleted_at IS NULL');
        $statement->execute([time(), $documentId, $id]);
        if ($statement->rowCount() !== 1) {
            throw new AdmissionProblem('Dokumen tidak ditemukan.', 404);
        }
        $db->prepare('UPDATE applications SET version = version + 1, updated_at = ? WHERE id = ?')->execute([time(), $id]);
        applicationEvent($db, $id, $userId, 'document_removed');
    });
}

function submitApplication(PDO $db, string $id, int $userId, int $version, bool $confirmed, string $storage): void
{
    admissionTransaction($db, function () use ($db, $id, $userId, $version, $confirmed, $storage): void {
        $application = ownedApplication($db, $id, $userId);
        if ($application['status'] === 'submitted') {
            return;
        }
        assertDraft($application, $version);
        $period = admissionPeriod($db, $application['period_id']);
        assertPeriodOpen($period);
        $errors = validateParticipant(admissionData($application['data_json']), true, $period['level']);
        $requirements = pathwayConfig($period, $application['pathway'])['documents'];
        $documents = applicationDocuments($db, $id);
        foreach ($requirements as $requirement) {
            $document = $documents[$requirement['code']] ?? null;
            if (!$document && $requirement['required']) {
                $errors['documents'] = 'Lengkapi seluruh dokumen wajib sebelum mengirim.';
            } elseif ($document) {
                $file = $storage . '/documents/' . $document['storage_name'];
                if (!is_file($file) || hash_file('sha256', $file) !== $document['sha256']) {
                    throw new RuntimeException('Integritas dokumen gagal. Pendaftaran belum dikirim.');
                }
            }
        }
        if (!$confirmed) {
            $errors['declaration'] = 'Baca pemberitahuan privasi dan konfirmasi kebenaran data sebelum mengirim.';
        }
        if ($errors) {
            throw new AdmissionProblem('Pendaftaran belum dapat dikirim. Periksa kelengkapan berikut.', 422, $errors);
        }
        $number = 'PPDB-' . gmdate('Y') . '-' . strtoupper($id);
        $db->prepare("UPDATE applications SET status = 'submitted', registration_number = ?, rule_snapshot_json = ?,
            declaration_at = ?, submitted_at = ?, updated_at = ?, version = version + 1 WHERE id = ?")
            ->execute([$number, $period['config_json'], time(), time(), time(), $id]);
        applicationEvent($db, $id, $userId, 'submitted');
    });
}

function validatePeriodConfiguration(array $input): array
{
    if (array_key_exists('operational', $input)) {
        throw new InvalidArgumentException('Paket operasional hanya dapat dipasang melalui persetujuan dan penerbitan di portal admin; hapus field operational dari import/salin konfigurasi.');
    }
    if (isset($input['admission_mode']) && !in_array($input['admission_mode'], ['public_spmb', 'private_independent'], true)) {
        throw new InvalidArgumentException('Mode penerimaan tidak valid.');
    }
    foreach (['code', 'organizer', 'school', 'level', 'academic_year', 'timezone', 'opens_at', 'closes_at', 'privacy_notice', 'help_contact', 'rule_reference'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '' || mb_strlen($input[$key]) > 3000
            || preg_match('/[\x00-\x1f\x7f]/', $input[$key])) {
            throw new InvalidArgumentException("Konfigurasi $key wajib berupa teks valid.");
        }
    }
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{2,49}\z/', $input['code']) || !in_array($input['level'], ['SD', 'SMP', 'SMA'], true)
        || !preg_match('/\A[0-9]{4}\/[0-9]{4}\z/', $input['academic_year'])
        || (int) substr($input['academic_year'], 5) !== (int) substr($input['academic_year'], 0, 4) + 1
        || !in_array($input['timezone'], ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'], true)
        || !isset($input['is_demo']) || !is_bool($input['is_demo'])) {
        throw new InvalidArgumentException('Kode, jenjang, tahun ajaran, zona waktu, atau is_demo tidak valid.');
    }
    $zone = new DateTimeZone($input['timezone']);
    foreach (['opens_at', 'closes_at'] as $key) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $input[$key], $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $input[$key]) {
            throw new InvalidArgumentException("$key harus menggunakan format Y-m-d H:i:s.");
        }
        $input[$key . '_timestamp'] = $date->getTimestamp();
    }
    if ($input['closes_at_timestamp'] <= $input['opens_at_timestamp']) {
        throw new InvalidArgumentException('Penutupan harus setelah pembukaan.');
    }
    if (!isset($input['pathways']) || !is_array($input['pathways']) || !array_is_list($input['pathways'])
        || count($input['pathways']) < 1 || count($input['pathways']) > 20) {
        throw new InvalidArgumentException('Masukkan 1 sampai 20 jalur.');
    }
    $codes = [];
    foreach ($input['pathways'] as $pathway) {
        if (!is_array($pathway) || !isset($pathway['code'], $pathway['name'], $pathway['description'], $pathway['documents'])
            || !is_string($pathway['code']) || !preg_match('/\A[a-z0-9-]{2,40}\z/', $pathway['code'])
            || in_array($pathway['code'], $codes, true) || !is_string($pathway['name']) || trim($pathway['name']) === ''
            || mb_strlen($pathway['name']) > 100 || !is_string($pathway['description']) || mb_strlen($pathway['description']) > 2000
            || !is_array($pathway['documents']) || !array_is_list($pathway['documents']) || count($pathway['documents']) > 10) {
            throw new InvalidArgumentException('Konfigurasi jalur tidak valid atau kode jalur duplikat.');
        }
        $codes[] = $pathway['code'];
        if (($input['admission_mode'] ?? '') === 'public_spmb'
            && (!in_array($pathway['code'], ['domisili', 'afirmasi', 'prestasi', 'mutasi'], true)
                || ($input['level'] === 'SD' && $pathway['code'] === 'prestasi'))) {
            throw new InvalidArgumentException('Jalur mode negeri harus domisili, afirmasi, prestasi, atau mutasi. Prestasi tidak berlaku untuk kelas 1 SD.');
        }
        $documentCodes = [];
        foreach ($pathway['documents'] as $document) {
            if (!is_array($document) || !isset($document['code'], $document['label'], $document['required'])
                || !is_string($document['code']) || !preg_match('/\A[a-z0-9-]{2,40}\z/', $document['code'])
                || in_array($document['code'], $documentCodes, true) || !is_string($document['label']) || trim($document['label']) === ''
                || mb_strlen($document['label']) > 150 || !is_bool($document['required'])) {
                throw new InvalidArgumentException('Konfigurasi dokumen tidak valid atau kode dokumen duplikat.');
            }
            $documentCodes[] = $document['code'];
        }
    }
    $input['version'] = 1;
    return $input;
}

function admissionModeLabel(array $configuration): string
{
    return match ($configuration['admission_mode'] ?? '') {
        'public_spmb' => 'Negeri · SPMB',
        'private_independent' => 'Swasta · Penerimaan mandiri',
        default => 'Mode belum ditetapkan',
    };
}

function admissionDisplayText(string $text): string
{
    return str_replace([
        ' — gunakan berkas fiktif untuk demo',
        ' — berkas fiktif untuk demo',
        ' — fiktif untuk demo',
        ' fiktif (DEMO)',
        ' (DEMO)',
    ], '', $text);
}

function admissionPresentation(array $period): array
{
    // Only presentation is normalized; stored configurations and submitted snapshots stay intact.
    $configuration = $period['configuration'] ?? admissionData($period['config_json']);
    foreach ($configuration['pathways'] as &$pathway) {
        foreach ($pathway['documents'] as &$document) {
            $document['label'] = admissionDisplayText($document['label']);
        }
        unset($document);
    }
    unset($pathway);
    $period['configuration'] = $configuration;
    $period['school'] = admissionDisplayText($period['school']);
    $period['organizer'] = admissionDisplayText($period['organizer']);
    return $period;
}

function insertAdmissionPeriod(PDO $db, array $configuration): string
{
    $data = validatePeriodConfiguration($configuration);
    return admissionTransaction($db, function () use ($db, $data): string {
        return persistAdmissionPeriod($db, $data);
    });
}

function persistAdmissionPeriod(PDO $db, array $data): string
{
    $exists = $db->prepare('SELECT id FROM admission_periods WHERE code = ?');
    $exists->execute([$data['code']]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('Kode periode sudah ada. Periode yang diterbitkan tidak dapat ditimpa; gunakan kode versi/periode baru.');
    }
    $id = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO admission_periods (id, code, organizer, school, level, academic_year, opens_at, closes_at, timezone, is_demo, config_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $data['code'], $data['organizer'], $data['school'], $data['level'], $data['academic_year'],
            $data['opens_at_timestamp'], $data['closes_at_timestamp'], $data['timezone'], (int) $data['is_demo'], admissionJson($data), time()]);
    audit($db, 'admission.period_configured');
    return $id;
}
