<?php
declare(strict_types=1);
$errors = [];
$application = null;
$request = null;
$revisions = [];
$requests = [];
$documents = [];
$notices = [];
$revision = null;
$reviewHistory = [];
$data = [];
$period = null;
$title = 'Koreksi dan riwayat kiriman';
$owner = (int)$user['id'];
$screen = 'corrections';
try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method,['GET','POST'],true)) {
        header('Allow: GET, POST');
        throw new AdmissionProblem('Metode permintaan tidak didukung.',405);
    }
    if ($method==='POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>0) {
            throw new AdmissionProblem('Permintaan terlalu besar. Unggah satu berkas maksimal 2 MB.',413);
        }
        if (!validCsrf()) throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang.',419);
        if ($config['environment'] !== 'development') throw new AdmissionProblem('Koreksi/notifikasi belum dibuka pada produksi.',403);
    }
    if ($path === '/applications/notifications') {
        $screen = 'notifications';
        $title = 'Notifikasi pendaftaran';
        if ($method === 'POST') {
            $statement = $db->prepare('UPDATE participant_notices SET read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?');
            $statement->execute([time(),input('notice_id'),$owner]);
            if ($statement->rowCount() !== 1) throw new AdmissionProblem('Notifikasi tidak ditemukan.',404);
            flash('Notifikasi ditandai dibaca.');
            redirect($path);
        }
        $statement = $db->prepare('SELECT * FROM participant_notices WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');
        $statement->execute([$owner]);
        $notices = $statement->fetchAll();
    } elseif (preg_match('~\A/applications/([a-f0-9]{32})/corrections(?:/([a-f0-9]{32}))?\z~',$path,$matches)) {
        $application = ownedApplication($db,$matches[1],$owner);
        if ($application['status']!=='submitted') throw new AdmissionProblem('Koreksi hanya untuk kiriman terkirim.',409);
        $period = admissionPeriod($db,$application['period_id']);
        $period['configuration'] = admissionData($application['rule_snapshot_json']);
        if (isset($matches[2])) {
            $request = ownedCorrection($db,$matches[2],$owner);
            if ($request['application_id']!==$application['id']) throw new AdmissionProblem('Koreksi tidak ditemukan.',404);
            if ($method==='POST') {
                $version = operationalInteger(input('version'),'Versi koreksi',1);
                $action = input('action');
                if ($action === 'save') {
                    $values = $_POST['fields'] ?? [];
                    if (!is_array($values)) throw new AdmissionProblem('Data koreksi tidak valid.',422);
                    foreach (admissionFields() as $key=>$label) {
                        if (array_key_exists($key,$_POST)) throw new AdmissionProblem('Gunakan hanya kolom koreksi yang disediakan.',422);
                    }
                    if (isset($_POST['pathway']) || isset($_POST['period_id'])) throw new AdmissionProblem('Jalur/periode terkunci.',422);
                    saveCorrectionFields($db,$request['id'],$owner,$version,$values);
                    flash('Draf koreksi tersimpan, belum dikirim ulang.');
                } elseif ($action==='upload') {
                    if (!consumeRateLimit($db,'document-upload',(string)$owner,20)) throw new AdmissionProblem('Terlalu banyak unggahan. Coba lagi dalam 15 menit.',429);
                    storeCorrectionDocument($db,$config,$request['id'],$owner,$version,input('kind'),$_FILES['document'] ?? []);
                    flash('Berkas koreksi tersimpan. Berkas awal tetap utuh.');
                } elseif ($action==='submit') {
                    submitCorrection($db,$config,$request['id'],$owner,$version,input('declaration')==='1');
                    flash('Revisi dikirim dan menunggu pemeriksaan ulang.');
                } else throw new AdmissionProblem('Aksi koreksi tidak valid.',422);
                redirect($path);
            }
            $data = admissionData($request['data_json']);
            $documents = revisionDocuments($db,$application['id'],$request['document_ids_json']);
        } else {
            if ($method!=='GET') throw new AdmissionProblem('Pilih permintaan koreksi untuk menyimpan.',405);
            $number = $_GET['revision'] ?? '';
            if (!is_string($number)) throw new AdmissionProblem('Nomor revisi tidak valid.',422);
            $revision = latestApplicationRevision($db,$application);
            if ($number !== '') {
                $n = filter_var($number,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if ($n===false) throw new AdmissionProblem('Nomor revisi tidak valid.',422);
                $statement = $db->prepare('SELECT * FROM application_revisions WHERE application_id=? AND revision=?');
                $statement->execute([$application['id'],$n]);
                $revision = $n===0 ? ['revision'=>0,'data_json'=>$application['data_json'],
                    'documents_json'=>admissionJson(array_map(fn(array $d):string=>$d['id'],applicationDocuments($db,$application['id']))),
                    'submitted_at'=>$application['submitted_at']] : ($statement->fetch() ?: throw new AdmissionProblem('Revisi tidak ditemukan.',404));
            }
            $data = admissionData($revision['data_json']);
            $documents = revisionDocuments($db,$application['id'],$revision['documents_json']);
        }
    } else throw new AdmissionProblem('Halaman koreksi tidak ditemukan.',404);
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors = ['form'=>$exception->getMessage()] + $exception->fields;
    if ($request && $application) {
        $data = admissionData($request['data_json']);
        $documents = revisionDocuments($db,$application['id'],$request['document_ids_json']);
    }
    if ($request && $application && $exception->status===422 && input('action')==='save') {
        foreach (admissionData($request['fields_json']) as $key=>$reason) {
            if (is_string($_POST['fields'][$key] ?? null)) $data[$key]=$_POST['fields'][$key];
        }
    }
}
if ($application && $period) {
    $statement = $db->prepare('SELECT revision,submitted_at FROM application_revisions WHERE application_id=? ORDER BY revision DESC');
    $statement->execute([$application['id']]);
    $revisions = $statement->fetchAll();
    $statement = $db->prepare('SELECT * FROM correction_requests WHERE application_id=? ORDER BY created_at DESC,id LIMIT 50');
    $statement->execute([$application['id']]);
    $requests = $statement->fetchAll();
    $statement = $db->prepare('SELECT * FROM detailed_review_history WHERE application_id=? ORDER BY id DESC LIMIT 50');
    $statement->execute([$application['id']]);
    $reviewHistory = $statement->fetchAll();
}
$notice = takeFlash();
require __DIR__ . '/views/corrections.php';
