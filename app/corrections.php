<?php
declare(strict_types=1);

function reviewCriteria(): array
{
    return [
        'identity'=>['label'=>'Identitas peserta','fields'=>['name','nisn','sex','birth_place','birth_date','source_school']],
        'residence'=>['label'=>'Domisili / alamat','fields'=>['address','province','city','district','village','postal_code']],
        'eligibility'=>['label'=>'Kelayakan jalur','fields'=>[]],
        'consistency'=>['label'=>'Konsistensi data wali/peserta','fields'=>['guardian_name','relationship','phone']],
    ];
}

function reviewStatuses(): array
{
    return ['pending'=>'Belum diperiksa','valid'=>'Valid','needs_correction'=>'Perlu perbaikan',
        'invalid'=>'Tidak valid','not_applicable'=>'Tidak berlaku (opsional tidak ada)'];
}

function latestApplicationRevision(PDO $db, array $application): array
{
    $statement = $db->prepare('SELECT * FROM application_revisions WHERE application_id=? ORDER BY revision DESC LIMIT 1');
    $statement->execute([$application['id']]);
    if ($revision = $statement->fetch()) {
        return $revision;
    }
    return ['revision'=>0,'data_json'=>$application['data_json'],
        'documents_json'=>admissionJson(array_map(fn(array $doc): string => $doc['id'], applicationDocuments($db, $application['id']))),
        'submitted_at'=>$application['submitted_at']];
}

function revisionDocuments(PDO $db, string $applicationId, string $json): array
{
    $documents = [];
    $statement = $db->prepare('SELECT * FROM application_documents WHERE id=? AND application_id=?');
    foreach (admissionData($json) as $kind=>$id) {
        $statement->execute([$id, $applicationId]);
        $document = $statement->fetch();
        if (!$document || $document['kind'] !== $kind) {
            throw new RuntimeException('Referensi dokumen revisi tidak valid.');
        }
        $documents[$kind] = $document;
    }
    return $documents;
}

function reviewVersion(PDO $db, string $id): int
{
    $statement = $db->prepare('SELECT version FROM detailed_reviews WHERE application_id=?');
    $statement->execute([$id]);
    $version = $statement->fetchColumn();
    if ($version !== false) {
        return (int) $version;
    }
    $statement = $db->prepare('SELECT version FROM application_verifications WHERE application_id=?');
    $statement->execute([$id]);
    return (int) $statement->fetchColumn();
}

function reviewChecklistItems(array $application, array $documents): array
{
    $period = ['configuration'=>admissionData($application['rule_snapshot_json'])];
    $items = [];
    foreach (pathwayConfig($period, $application['pathway'])['documents'] as $doc) {
        $items['doc_' . $doc['code']] = ['label'=>$doc['label'],'required'=>$doc['required'],
            'present'=>isset($documents[$doc['code']])];
    }
    foreach (reviewCriteria() as $key=>$criterion) {
        $items['criterion_' . $key] = ['label'=>$criterion['label'],'required'=>true,'present'=>true];
    }
    return $items;
}

function validatedReviewChecklist(mixed $input, array $items): array
{
    if (!is_array($input) || count($input) !== count($items) || array_diff(array_keys($input), array_keys($items))) {
        throw new AdmissionProblem('Lengkapi tepat seluruh item checklist dokumen dan kriteria.', 422);
    }
    $result = [];
    foreach ($items as $key=>$item) {
        $row = $input[$key] ?? null;
        if (!is_array($row) || !is_string($row['status'] ?? null) || !is_string($row['note'] ?? null)
            || !in_array($row['status'], ['pending','valid','needs_correction','invalid','not_applicable'], true)) {
            throw new AdmissionProblem('Status/catatan checklist tidak valid.', 422);
        }
        $note = trim($row['note']);
        if (mb_strlen($note) > 2000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)
            || ($row['status'] !== 'pending' && mb_strlen($note) < 5)) {
            throw new AdmissionProblem('Catatan setiap item diperiksa wajib 5–2000 karakter.', 422);
        }
        if (($row['status'] === 'not_applicable' && ($item['required'] || $item['present']))
            || ($row['status'] === 'valid' && !$item['present'])) {
            throw new AdmissionProblem('Dokumen tidak diunggah tidak dapat Valid; Tidak berlaku hanya untuk dokumen opsional yang tidak ada.', 422);
        }
        $result[$key] = ['status'=>$row['status'],'note'=>$note];
    }
    return $result;
}

function correctionWindow(array $application): array
{
    $rules = admissionData($application['rule_snapshot_json']);
    $data = $rules['operational']['data'] ?? null;
    if (!$data) {
        throw new AdmissionProblem('Kiriman tanpa paket operasional tidak dapat membuka koreksi. Gunakan periode baru dengan paket disetujui.', 409);
    }
    $stage = $data['schedule']['correction'];
    return [operationalDate($stage['start'], 'Awal perbaikan', 'Y-m-d H:i:s', $data['timezone'])->getTimestamp(),
        operationalDate($stage['end'], 'Akhir perbaikan', 'Y-m-d H:i:s', $data['timezone'])->getTimestamp()];
}

function assertCorrectionWindow(int $start, int $end): void
{
    if (time() < $start || time() >= $end) {
        throw new AdmissionProblem('Koreksi hanya dapat diminta/disimpan/dikirim selama tahap Perbaikan pada snapshot aturan.', 409);
    }
}

function openCorrection(PDO $db, string $id): ?array
{
    $statement = $db->prepare("SELECT * FROM correction_requests WHERE application_id=? AND status='open'");
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function participantNotice(PDO $db, array $application, string $message): void
{
    $db->prepare('INSERT INTO participant_notices(id,user_id,application_id,message,created_at) VALUES(?,?,?,?,?)')
        ->execute([bin2hex(random_bytes(16)), $application['user_id'], $application['id'], $message, time()]);
}

function requestCorrection(PDO $db, array $application, array $revision, array $checklist, mixed $fields, mixed $docs, string $note, int $actor): void
{
    [$start, $deadline] = correctionWindow($application);
    assertCorrectionWindow($start, $deadline);
    if (!is_array($fields) || !is_array($docs) || (!$fields && !$docs)) {
        throw new AdmissionProblem('Pilih minimal satu kolom atau berkas untuk diperbaiki beserta alasan.', 422);
    }
    $allowedFields = [];
    foreach (reviewCriteria() as $key=>$criterion) {
        if ($checklist['criterion_' . $key]['status'] === 'needs_correction') {
            $allowedFields = [...$allowedFields, ...$criterion['fields']];
        }
    }
    $allowedDocs = [];
    foreach ($checklist as $key=>$row) {
        if (str_starts_with($key, 'doc_') && $row['status'] === 'needs_correction') {
            $allowedDocs[] = substr($key, 4);
        }
    }
    foreach ([$fields, $docs] as $index=>$selections) {
        foreach ($selections as $key=>$reason) {
            if (!in_array($key, $index === 0 ? $allowedFields : $allowedDocs, true)) {
                throw new AdmissionProblem('Kolom/berkas terpilih harus sesuai item checklist Perlu perbaikan. Jalur dan aturan tidak dapat diganti.', 422);
            }
            operationalText($reason, 'Alasan koreksi', 2000);
            if (mb_strlen(trim($reason)) < 5) {
                throw new AdmissionProblem('Alasan tiap koreksi wajib minimal 5 karakter.', 422);
            }
        }
    }
    if ($previous = openCorrection($db, $application['id'])) {
        throw new AdmissionProblem('Masih ada permintaan koreksi terbuka. Tunggu kiriman wali sebelum meminta lagi.', 409);
    }
    if ((int) $revision['revision'] === 0) {
        $db->prepare('INSERT OR IGNORE INTO application_revisions(id,application_id,revision,data_json,documents_json,created_by,submitted_at) VALUES(?,?,0,?,?,?,?)')
            ->execute([bin2hex(random_bytes(16)), $application['id'], $revision['data_json'], $revision['documents_json'],
                $application['user_id'], $application['submitted_at']]);
    }
    $id = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO correction_requests(id,application_id,base_revision,fields_json,documents_json,data_json,document_ids_json,
        opens_at,deadline,note,requested_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $application['id'], $revision['revision'], admissionJson($fields), admissionJson($docs),
            $revision['data_json'], $revision['documents_json'], $start, $deadline, $note, $actor, time()]);
    participantNotice($db, $application, 'Panitia meminta koreksi terbatas. ' . $note);
    applicationEvent($db, $application['id'], $actor, 'correction_requested');
}

function saveDetailedReview(PDO $db, int $actorId, string $id, int $expected, string $action, string $decision,
    string $note, mixed $input, mixed $fields = [], mixed $docs = []): void
{
    admissionTransaction($db, function () use ($db,$actorId,$id,$expected,$action,$decision,$note,$input,$fields,$docs): void {
        $application = adminApplication($db, $id, staffIdentity($db, $actorId));
        if (reviewVersion($db, $id) !== $expected) {
            throw new AdmissionProblem('Checklist/verifikasi atau revisi sudah berubah. Muat ulang sebelum menyimpan.', 409);
        }
        if (!in_array($action, ['save-checklist','verify','request-correction'], true)) {
            throw new AdmissionProblem('Aksi pemeriksaan tidak valid.', 422);
        }
        $revision = latestApplicationRevision($db, $application);
        $documents = revisionDocuments($db, $id, $revision['documents_json']);
        $checklist = validatedReviewChecklist($input, reviewChecklistItems($application, $documents));
        $note = trim($note);
        if ($action !== 'save-checklist' || $note !== '') {
            operationalText($note, 'Catatan keputusan', 2000);
        }
        if ($action === 'save-checklist' && applicationVerification($db,$id)) {
            throw new AdmissionProblem('Sudah ada keputusan untuk revisi ini. Gunakan Simpan keputusan untuk memperbarui checklist dan keputusan bersama.',409);
        }
        if ($action !== 'save-checklist') {
            if (!in_array($decision, ['valid','needs_correction','invalid'], true)) {
                throw new AdmissionProblem('Pilih keputusan verifikasi yang valid.', 422);
            }
            if (mb_strlen($note) < 5) {
                throw new AdmissionProblem('Catatan keputusan wajib minimal 5 karakter.', 422);
            }
            if ($request = openCorrection($db, $id)) {
                if (time() < (int) $request['deadline']) {
                    throw new AdmissionProblem('Tunggu wali mengirim koreksi sebelum memutuskan ulang.', 409);
                }
                $db->prepare("UPDATE correction_requests SET status='expired',version=version+1 WHERE id=?")->execute([$request['id']]);
            }
            if ($decision === 'valid') {
                foreach ($checklist as $row) {
                    if (!in_array($row['status'], ['valid','not_applicable'], true)) {
                        throw new AdmissionProblem('Keputusan Valid memerlukan seluruh dokumen/kriteria Valid (opsional tidak ada: Tidak berlaku).', 422);
                    }
                }
            } elseif (!in_array($decision,array_column($checklist,'status'),true)) {
                throw new AdmissionProblem('Keputusan Perlu perbaikan/Tidak valid harus memiliki item checklist dengan status yang sama.',422);
            }
            if ($action === 'request-correction') {
                if ($decision !== 'needs_correction') {
                    throw new AdmissionProblem('Permintaan koreksi harus berkeputusan Perlu perbaikan.', 422);
                }
                requestCorrection($db, $application, $revision, $checklist, $fields, $docs, $note, $actorId);
            }
        }
        $version = $expected + 1;
        $db->prepare('INSERT INTO detailed_reviews(application_id,revision,version,checklist_json,reviewer_id,updated_at) VALUES(?,?,?,?,?,?)
            ON CONFLICT(application_id) DO UPDATE SET revision=excluded.revision,version=excluded.version,
                checklist_json=excluded.checklist_json,reviewer_id=excluded.reviewer_id,updated_at=excluded.updated_at')
            ->execute([$id, $revision['revision'], $version, admissionJson($checklist), $actorId, time()]);
        $db->prepare('INSERT INTO detailed_review_history(application_id,revision,checklist_json,decision,note,reviewer_id,created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $revision['revision'], admissionJson($checklist), $action === 'save-checklist' ? null : $decision, $note, $actorId, time()]);
        if ($action !== 'save-checklist') {
            $db->prepare('INSERT INTO application_verifications(application_id,status,note,reviewer_id,version,updated_at) VALUES(?,?,?,?,?,?)
                ON CONFLICT(application_id) DO UPDATE SET status=excluded.status,note=excluded.note,reviewer_id=excluded.reviewer_id,
                version=excluded.version,updated_at=excluded.updated_at')->execute([$id,$decision,$note,$actorId,$version,time()]);
            $db->prepare('INSERT INTO verification_history(application_id,status,note,reviewer_id,created_at) VALUES(?,?,?,?,?)')
                ->execute([$id,$decision,$note,$actorId,time()]);
            if ($action !== 'request-correction') {
                participantNotice($db, $application, 'Verifikasi revisi ' . $revision['revision'] . ': ' . verificationLabel($decision) . '. ' . $note);
            }
        }
        applicationEvent($db, $id, $actorId, $action === 'save-checklist' ? 'checklist_saved' : 'verification_updated');
    });
}

function ownedCorrection(PDO $db, string $id, int $owner): array
{
    $statement = $db->prepare('SELECT c.* FROM correction_requests c JOIN applications a ON a.id=c.application_id WHERE c.id=? AND a.user_id=?');
    $statement->execute([$id,$owner]);
    return $statement->fetch() ?: throw new AdmissionProblem('Permintaan koreksi tidak ditemukan.', 404);
}

function writableCorrection(PDO $db, string $id, int $owner, int $version): array
{
    $request = ownedCorrection($db, $id, $owner);
    if ($request['status'] !== 'open' || (int) $request['version'] !== $version) {
        throw new AdmissionProblem('Koreksi sudah berubah/dikirim. Muat ulang formulir.', 409);
    }
    assertCorrectionWindow((int)$request['opens_at'],(int)$request['deadline']);
    return $request;
}

function saveCorrectionFields(PDO $db, string $id, int $owner, int $version, array $values): void
{
    admissionTransaction($db, function () use ($db,$id,$owner,$version,$values): void {
        $request = writableCorrection($db,$id,$owner,$version);
        $allowed = admissionData($request['fields_json']);
        if (array_diff(array_keys($values),array_keys($allowed))) {
            throw new AdmissionProblem('Hanya kolom yang diminta panitia dapat diubah.',422);
        }
        $data = admissionData($request['data_json']);
        foreach ($allowed as $key=>$reason) {
            if (!is_string($values[$key] ?? null)) {
                throw new AdmissionProblem('Lengkapi seluruh kolom koreksi terpilih.',422);
            }
            $data[$key] = trim($values[$key]);
        }
        $application = ownedApplication($db,$request['application_id'],$owner);
        $rules = admissionData($application['rule_snapshot_json']);
        $errors = validateParticipant($data,true,$rules['level']);
        if ($errors) {
            throw new AdmissionProblem('Periksa isian koreksi. Data belum disimpan.',422,$errors);
        }
        $db->prepare('UPDATE correction_requests SET data_json=?,version=version+1 WHERE id=?')->execute([admissionJson($data),$id]);
        applicationEvent($db,$application['id'],$owner,'correction_saved');
    });
}

function storeCorrectionDocument(PDO $db, array $config, string $id, int $owner, int $version, string $kind, array $file): void
{
    $upload = prepareApplicationUpload($config,$file);
    try {
        admissionTransaction($db, function () use ($db,$id,$owner,$version,$kind,$upload): void {
            $request = writableCorrection($db,$id,$owner,$version);
            if (!array_key_exists($kind,admissionData($request['documents_json']))) {
                throw new AdmissionProblem('Berkas ini tidak diminta untuk koreksi.',422);
            }
            $hash = hash_file('sha256',$upload['temporary']);
            if ($hash === false || !move_uploaded_file($upload['temporary'],$upload['destination'])) {
                throw new RuntimeException('Gagal menyimpan berkas koreksi.');
            }
            $db->prepare('INSERT INTO application_documents(id,application_id,kind,original_name,storage_name,mime_type,size,sha256,uploaded_at,deleted_at)
                VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$upload['documentId'],$request['application_id'],$kind,$upload['name'],$upload['storageName'],
                    $upload['mime'],$upload['size'],$hash,time(),time()]);
            $db->prepare('INSERT INTO correction_uploads(request_id,document_id) VALUES(?,?)')->execute([$id,$upload['documentId']]);
            $ids = admissionData($request['document_ids_json']);
            $ids[$kind] = $upload['documentId'];
            $db->prepare('UPDATE correction_requests SET document_ids_json=?,version=version+1 WHERE id=?')->execute([admissionJson($ids),$id]);
            applicationEvent($db,$request['application_id'],$owner,'correction_document_uploaded');
        });
    } catch (Throwable $exception) {
        if (is_file($upload['destination']) && !unlink($upload['destination'])) {
            error_log('[PPDB] Berkas koreksi gagal dibersihkan setelah rollback: ' . $upload['storageName']);
        }
        throw $exception;
    }
}

function submitCorrection(PDO $db,array $config,string $id,int $owner,int $version,bool $confirmed): void
{
    admissionTransaction($db,function () use ($db,$config,$id,$owner,$version,$confirmed): void {
        $request = ownedCorrection($db,$id,$owner);
        if ($request['status'] === 'submitted') {
            return;
        }
        $request = writableCorrection($db,$id,$owner,$version);
        if (!$confirmed) {
            throw new AdmissionProblem('Konfirmasi kebenaran revisi sebelum mengirim.',422);
        }
        $application = ownedApplication($db,$request['application_id'],$owner);
        $latest = latestApplicationRevision($db,$application);
        if ((int)$latest['revision'] !== (int)$request['base_revision']) {
            throw new AdmissionProblem('Revisi dasar sudah berubah. Hubungi panitia.',409);
        }
        $data = admissionData($request['data_json']);
        $base = admissionData($latest['data_json']);
        foreach (admissionData($request['fields_json']) as $key=>$reason) {
            if ($data[$key] === $base[$key]) {
                throw new AdmissionProblem('Kolom ' . admissionFields()[$key][0] . ' belum diperbaiki.',422);
            }
        }
        $documents = revisionDocuments($db,$application['id'],$request['document_ids_json']);
        $oldIds = admissionData($latest['documents_json']);
        foreach (admissionData($request['documents_json']) as $kind=>$reason) {
            if (!isset($documents[$kind]) || $documents[$kind]['id'] === ($oldIds[$kind] ?? null)) {
                throw new AdmissionProblem('Unggah pengganti seluruh berkas yang diminta sebelum mengirim.',422);
            }
        }
        $rules = admissionData($application['rule_snapshot_json']);
        $errors = validateParticipant($data,true,$rules['level']);
        if ($errors) {
            throw new AdmissionProblem('Data revisi belum lengkap.',422,$errors);
        }
        foreach ($documents as $doc) {
            $file = $config['storage'] . '/documents/' . $doc['storage_name'];
            if (!is_file($file) || hash_file('sha256',$file) !== $doc['sha256']) {
                throw new RuntimeException('Integritas berkas revisi gagal. Revisi belum dikirim.');
            }
        }
        $revision = (int)$latest['revision'] + 1;
        $db->prepare('INSERT INTO application_revisions(id,application_id,revision,data_json,documents_json,created_by,submitted_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([bin2hex(random_bytes(16)),$application['id'],$revision,$request['data_json'],$request['document_ids_json'],$owner,time()]);
        $db->prepare("UPDATE correction_requests SET status='submitted',submitted_at=?,submitted_revision=?,version=version+1 WHERE id=?")
            ->execute([time(),$revision,$id]);
        $db->prepare('DELETE FROM application_verifications WHERE application_id=?')->execute([$application['id']]);
        $db->prepare("UPDATE detailed_reviews SET revision=?,version=version+1,checklist_json='{}',updated_at=? WHERE application_id=?")
            ->execute([$revision,time(),$application['id']]);
        participantNotice($db,$application,'Revisi ' . $revision . ' berhasil dikirim dan menunggu pemeriksaan ulang.');
        applicationEvent($db,$application['id'],$owner,'correction_submitted');
    });
}

function correctionDocumentVisible(PDO $db,array $document,bool $staff,int $owner): bool
{
    $statement = $db->prepare('SELECT documents_json FROM application_revisions WHERE application_id=?');
    $statement->execute([$document['application_id']]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
        if (in_array($document['id'],admissionData($json),true)) {
            return true;
        }
    }
    if (!$staff) {
        $statement = $db->prepare('SELECT 1 FROM correction_uploads u JOIN correction_requests c ON c.id=u.request_id
            JOIN applications a ON a.id=c.application_id WHERE u.document_id=? AND a.user_id=?');
        $statement->execute([$document['id'],$owner]);
        return (bool)$statement->fetchColumn();
    }
    return false;
}
