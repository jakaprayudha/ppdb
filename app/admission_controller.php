<?php
declare(strict_types=1);

require __DIR__ . '/admissions.php';
require __DIR__ . '/admin.php';
if ($path === '/applications/notifications' || preg_match('~\A/applications/[a-f0-9]{32}/corrections(?:/|$)~',$path)) {
    require __DIR__ . '/correction_controller.php';
    exit;
}
if ($path === '/participants/location') {
    require __DIR__ . '/location.php';
    handleLocationRequest($db, $config, (int) $user['id']);
    exit;
}

function admissionQuery(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? $value : '';
}

function postedVersion(): int
{
    $version = filter_var(input('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($version === false) {
        throw new AdmissionProblem('Versi formulir tidak valid. Muat ulang halaman.', 409);
    }
    return $version;
}

$userId = (int) $user['id'];
$screen = 'not-found';
$errors = [];
$notice = null;
$profile = null;
$application = null;
$period = null;
$documents = [];
$data = [];
$step = 1;
$readonly = false;
$profiles = [];
$applications = [];
$periods = [];
$events = [];
$verification = null;
$unreadNotices = [];
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/dashboard') {
    $screen = 'dashboard';
} elseif ($path === '/participants') {
    $screen = 'participants';
} elseif ($path === '/participants/new') {
    $screen = 'profile';
} elseif (preg_match('~\A/participants/([a-f0-9]{32})\z~', $path, $matches)) {
    $screen = 'profile';
    $profileId = $matches[1];
} elseif ($path === '/admissions') {
    $screen = 'periods';
} elseif ($path === '/applications/new') {
    $screen = 'new-application';
} elseif (preg_match('~\A/applications/([a-f0-9]{32})(/(receipt|cancel))?\z~', $path, $matches)) {
    $screen = $matches[3] ?? 'application';
    $applicationId = $matches[1];
} elseif (preg_match('~\A/documents/([a-f0-9]{32})\z~', $path, $matches)) {
    $screen = 'document';
    $documentId = $matches[1];
}

try {
    $allowed = in_array($screen, ['profile', 'new-application', 'application', 'cancel'], true) ? ['GET', 'POST'] : ['GET'];
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    if ($screen === 'not-found') {
        throw new AdmissionProblem('Halaman tidak ditemukan.', 404);
    }
    if ($method === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new AdmissionProblem('Permintaan terlalu besar. Unggah satu berkas maksimal 2 MB.', 413);
    }
    if ($screen === 'profile') {
        $data = array_fill_keys(array_keys(admissionFields()), '');
        $data['guardian_name'] = $user['name'];
        if (isset($profileId)) {
            $profile = ownedProfile($db, $profileId, $userId);
            $data = admissionData($profile['data_json']);
        }
    }
    if ($screen === 'new-application') {
        $periodId = $method === 'POST' ? input('period_id') : admissionQuery('period');
        $period = admissionPeriod($db, $periodId);
        $selectedProfile = $method === 'POST' ? input('profile_id') : admissionQuery('profile');
        $selectedPathway = $method === 'POST' ? input('pathway') : '';
        if (!(int) $period['enabled']) {
            throw new AdmissionProblem('Periode diarsipkan. Pilih periode SMP negeri yang tersedia.', 409);
        }
    }
    if (in_array($screen, ['application', 'receipt', 'cancel'], true)) {
        $application = ownedApplication($db, $applicationId, $userId);
        $verification = applicationVerification($db, $applicationId);
        $period = admissionPeriod($db, $application['period_id']);
        if ($application['status'] === 'submitted') {
            $period['configuration'] = admissionData($application['rule_snapshot_json']);
        }
        $data = admissionData($application['data_json']);
        $documents = applicationDocuments($db, $applicationId);
        $selectedPathway = $application['pathway'];
        $stepString = admissionQuery('step') ?: '1';
        if (!in_array($stepString, ['1', '2', '3', '4'], true)) {
            throw new AdmissionProblem('Langkah formulir tidak valid.', 404);
        }
        $step = (int) $stepString;
        $readonly = $application['status'] !== 'draft' || periodState($period) !== 'Pendaftaran dibuka' || $config['environment'] === 'production';
        if ($screen === 'receipt' && $application['status'] !== 'submitted') {
            throw new AdmissionProblem('Tanda terima tersedia setelah pendaftaran dikirim.', 409);
        }
        if ($screen === 'cancel' && $application['status'] !== 'draft') {
            throw new AdmissionProblem('Pendaftaran sudah dikirim dan tidak dapat dibatalkan melalui penghapusan draf.', 409);
        }
    }
    if ($screen === 'document') {
        $adminDocumentAccess = isStaff($user);
        if ($adminDocumentAccess && $config['environment'] !== 'development') {
            throw new AdmissionProblem('Akses dokumen admin belum dibuka pada produksi.', 403);
        }
        $statement = $db->prepare('SELECT d.* FROM application_documents d JOIN applications a ON a.id = d.application_id
            WHERE d.id = ? AND (a.user_id = ? OR (CAST(? AS INTEGER) = 1 AND a.status = \'submitted\'))');
        $statement->execute([$documentId, $userId, (int) $adminDocumentAccess]);
        $document = $statement->fetch();
        if (!$document) {
            throw new AdmissionProblem('Dokumen tidak ditemukan.', 404);
        }
        if ($document['deleted_at'] !== null && !correctionDocumentVisible($db,$document,$adminDocumentAccess,$userId)) {
            throw new AdmissionProblem('Dokumen versi ini tidak ditemukan atau belum dikirim.',404);
        }
        if ($adminDocumentAccess) {
            authorizeStaffApplication($db, $user, $document['application_id']);
        }
        $file = $config['storage'] . '/documents/' . $document['storage_name'];
        if (!is_file($file) || hash_file('sha256', $file) !== $document['sha256']) {
            throw new RuntimeException('Dokumen tidak tersedia atau integritasnya gagal.');
        }
        applicationEvent($db, $document['application_id'], $userId, 'document_accessed');
        if ($adminDocumentAccess) {
            audit($db, 'admin.document_accessed', $userId);
        }
        header('Content-Type: ' . $document['mime_type']);
        $disposition = $document['mime_type'] === 'application/pdf' || admissionQuery('download') === '1' ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="dokumen.' . pathinfo($document['storage_name'], PATHINFO_EXTENSION)
            . '"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Length: ' . $document['size']);
        session_write_close();
        if (readfile($file) === false) {
            throw new RuntimeException('Dokumen gagal dibaca.');
        }
        exit;
    }
    if ($method === 'POST') {
        if (!validCsrf()) {
            throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman sebelum mencoba lagi.', 419);
        }
        if ($config['environment'] === 'production') {
            throw new AdmissionProblem('Modul registrasi masih tahap pengembangan dan belum dibuka untuk data nyata.', 403);
        }
        if ($screen === 'cancel') {
            if (input('confirm_cancel') !== '1') {
                throw new AdmissionProblem('Konfirmasi penghapusan draf diperlukan.', 422);
            }
            cancelApplication($db, $config['storage'], $applicationId, $userId, postedVersion());
            flash('Draf pendaftaran dan seluruh berkasnya telah dihapus. Profil peserta tetap tersedia.');
            redirect('/admissions');
        }
        if ($screen === 'profile') {
            $data = participantInput();
            $validation = validateParticipant($data, false, 'SD', true);
            if ($validation) {
                throw new AdmissionProblem('Profil belum disimpan. Periksa kembali isian berikut.', 422, $validation);
            }
            $id = admissionTransaction($db, function () use ($db, $userId, $profile, $data): string {
                if ($profile) {
                    $current = ownedProfile($db, $profile['id'], $userId);
                    if ((int) $current['version'] !== postedVersion()) {
                        throw new AdmissionProblem('Profil telah berubah di tab/perangkat lain. Muat ulang untuk menghindari data tertimpa.', 409);
                    }
                    $db->prepare('UPDATE participant_profiles SET data_json = ?, version = version + 1, updated_at = ? WHERE id = ?')
                        ->execute([admissionJson($data), time(), $profile['id']]);
                    $id = $profile['id'];
                } else {
                    $id = bin2hex(random_bytes(16));
                    $db->prepare('INSERT INTO participant_profiles (id, user_id, data_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$id, $userId, admissionJson($data), time(), time()]);
                }
                audit($db, $profile ? 'admission.profile_updated' : 'admission.profile_created', $userId);
                return $id;
            });
            flash('Profil peserta tersimpan. Profil ini dapat dipakai untuk membuat draf pendaftaran.');
            redirect('/participants/' . $id);
        }
        if ($screen === 'new-application') {
            $id = createApplication($db, $userId, $selectedProfile, $period['id'], $selectedPathway);
            flash('Draf tersedia. Lengkapi data pada tiap langkah, lalu tinjau dan kirim.');
            redirect('/applications/' . $id);
        }
        if ($screen === 'application') {
            $action = input('action');
            if ($action === 'save' && in_array($step, [1, 2], true)) {
                $submitted = participantInput();
                $keys = $step === 1 ? ['name', 'nisn', 'sex', 'birth_place', 'birth_date', 'source_school']
                    : ['guardian_name', 'relationship', 'phone', 'address', 'province', 'city', 'district', 'village', 'postal_code'];
                foreach ($keys as $key) {
                    $data[$key] = $submitted[$key];
                }
                if ($step === 2) {
                    $selectedPathway = input('pathway');
                }
                saveApplicationStep($db, $applicationId, $userId, postedVersion(), $step, $submitted, $selectedPathway);
                flash('Draf berhasil disimpan pada ' . admissionDate(time(), $period['timezone']) . '.');
                $next = input('next') === '1' ? $step + 1 : $step;
                redirect('/applications/' . $applicationId . '?step=' . $next);
            } elseif ($action === 'upload' && $step === 3) {
                if (!consumeRateLimit($db, 'document-upload', (string) $userId, 30)) {
                    header('Retry-After: 900');
                    throw new AdmissionProblem('Terlalu banyak unggahan. Coba lagi dalam 15 menit.', 429);
                }
                $file = $_FILES['document'] ?? [];
                if (!is_array($file)) {
                    throw new AdmissionProblem('Unggahan tidak valid.');
                }
                storeApplicationDocument($db, $config, $applicationId, $userId, postedVersion(), input('kind'), $file);
                flash('Dokumen berhasil diunggah dan disimpan.');
                redirect('/applications/' . $applicationId . '?step=3');
            } elseif ($action === 'remove-document' && $step === 3) {
                removeApplicationDocument($db, $applicationId, $userId, postedVersion(), input('document_id'));
                flash('Dokumen dihapus dari checklist. Salinan sebelumnya tetap disimpan untuk audit sampai retensi dijalankan.');
                redirect('/applications/' . $applicationId . '?step=3');
            } elseif ($action === 'submit' && $step === 4) {
                submitApplication($db, $applicationId, $userId, postedVersion(), input('declaration') === '1', $config['storage']);
                flash('Pendaftaran berhasil dikirim. Simpan tanda terima; pengiriman bukan keputusan diterima.');
                redirect('/applications/' . $applicationId . '/receipt');
            } else {
                throw new AdmissionProblem('Aksi tidak sesuai dengan langkah formulir.', 400);
            }
        }
    }
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors = ['form' => $exception->getMessage()] + $exception->fields;
    if ($exception->status === 404 || $screen === 'document' || ($screen === 'cancel' && $exception->status === 503)) {
        $screen = 'not-found';
    }
}

$statement = $db->prepare('SELECT * FROM participant_profiles WHERE user_id = ? ORDER BY updated_at DESC');
$statement->execute([$userId]);
$profiles = $statement->fetchAll();
$statement = $db->prepare('SELECT a.*, COALESCE(latest.data_json,a.data_json) AS current_data_json,
    COALESCE(latest.revision,0) AS current_revision, COALESCE(latest.submitted_at,a.updated_at) AS current_saved_at,
    p.school, p.academic_year, p.is_demo, p.timezone, r.status AS verification_status, MIN(COALESCE(v.enabled, 1), COALESCE(s.enabled, 1)) AS period_enabled FROM applications a
    JOIN admission_periods p ON p.id = a.period_id LEFT JOIN admission_period_availability v ON v.period_id = p.id
    LEFT JOIN application_verifications r ON r.application_id = a.id
    LEFT JOIN application_revisions latest ON latest.application_id=a.id AND latest.revision=(
        SELECT MAX(revision) FROM application_revisions WHERE application_id=a.id)
    LEFT JOIN period_school_links l ON l.period_id = p.id LEFT JOIN master_schools s ON s.id = l.school_id
    WHERE a.user_id = ? ORDER BY a.updated_at DESC');
$statement->execute([$userId]);
$applications = $statement->fetchAll();
foreach ($applications as &$row) {
    if (!operationalPeriodReady($db, $row['period_id'])) {
        $row['period_enabled'] = 0;
    }
}
unset($row);
$periods = $db->query('SELECT p.* FROM admission_periods p LEFT JOIN admission_period_availability v ON v.period_id = p.id
    LEFT JOIN period_school_links l ON l.period_id = p.id LEFT JOIN master_schools s ON s.id = l.school_id
    WHERE COALESCE(v.enabled, 1) = 1 AND COALESCE(s.enabled, 1) = 1 ORDER BY p.is_demo ASC, p.school COLLATE NOCASE, p.opens_at DESC')->fetchAll();
$periods = array_values(array_filter($periods, fn(array $row): bool => operationalPeriodReady($db, $row['id'])));
$districts = [];
foreach ($periods as $item) {
    $district = admissionData($item['config_json'])['district'] ?? '';
    if ($district !== '') {
        $districts[$district] = $district;
    }
}
sort($districts);
$totalPeriods = count($periods);
if ($screen === 'periods') {
    $search = mb_strtolower(trim(admissionQuery('q')));
    $districtFilter = admissionQuery('district');
    $periods = array_values(array_filter($periods, static function (array $item) use ($search, $districtFilter): bool {
        $configuration = admissionData($item['config_json']);
        return ($districtFilter === '' || ($configuration['district'] ?? '') === $districtFilter)
            && ($search === '' || str_contains(mb_strtolower($item['school'] . ' ' . ($configuration['npsn'] ?? '') . ' ' . ($configuration['district'] ?? '')), $search));
    }));
}
if ($application && in_array($screen, ['application', 'receipt'], true)) {
    $statement = $db->prepare("SELECT action, created_at FROM application_events WHERE application_id = ?
        AND action IN ('draft_created', 'draft_saved', 'document_uploaded', 'document_removed', 'submitted',
            'correction_requested','correction_saved','correction_document_uploaded','correction_submitted') ORDER BY id DESC LIMIT 30");
    $statement->execute([$application['id']]);
    $events = array_reverse($statement->fetchAll());
}
$notice = takeFlash();
$statement = $db->prepare('SELECT * FROM participant_notices WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 5');
$statement->execute([$userId]);
$unreadNotices = $statement->fetchAll();
if ($period) {
    $period = admissionPresentation($period);
}
$periods = array_map('admissionPresentation', $periods);
require __DIR__ . '/views/admission.php';
