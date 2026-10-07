<?php
declare(strict_types=1);

require __DIR__ . '/admissions.php';
require __DIR__ . '/admin.php';
if ($path === '/admin/applications' || $path === '/admin/queue' || str_starts_with($path, '/admin/queue/')) {
    require __DIR__ . '/queue_controller.php';
    exit;
}
if (str_starts_with($path, '/admin/master-data/years') || str_starts_with($path, '/admin/master-data/rules')
    || $path === '/admin/rule-approvals' || str_starts_with($path, '/admin/rule-approvals/')) {
    require __DIR__ . '/operational_controller.php';
    exit;
}
if ($path === '/admin/accounts' || str_starts_with($path, '/admin/accounts/')) {
    require __DIR__ . '/staff_controller.php';
    exit;
}
if ($path === '/admin/master-data' || str_starts_with($path, '/admin/master-data/')) {
    require __DIR__ . '/master_controller.php';
    exit;
}

$errors = [];
$screen = 'not-found';
$application = null;
$verification = null;
$documents = [];
$history = [];
$data = [];
$decision = input('decision');
$note = input('note');
$periods = [];
$rows = [];
$total = 0;
$stats = [];
$assignment = null;
$reviewers = [];
$assignmentHistory = [];
$duplicatePeers = [];
$checklistItems = [];
$checklistValues = [];
$reviewFormVersion = 0;
$reviewHistory = [];
$revisions = [];
$correctionRequests = [];
$reviewedRevision = 0;
$revisionView = '';
$adminAllowed = isStaff($user) && $config['environment'] === 'development';

try {
    if (!$adminAllowed) {
        throw new AdmissionProblem(isStaff($user)
            ? 'Portal admin belum dibuka pada produksi. MFA dan persetujuan operasional diperlukan.'
            : 'Halaman ini hanya untuk staf yang ditugaskan.', 403);
    }
    $screens = ['/admin' => 'dashboard', '/admin/applications' => 'applications', '/admin/master-data' => 'master',
        '/admin/accounts' => 'accounts', '/admin/audit' => 'audit'];
    $screen = $screens[$path] ?? 'not-found';
    if (preg_match('~\A/admin/applications/([a-f0-9]{32})\z~', $path, $matches)) {
        $screen = 'review';
        $application = adminApplication($db, $matches[1], $user);
    }
    if ($screen === 'not-found') {
        throw new AdmissionProblem('Halaman tidak ditemukan.', 404);
    }
    if ($screen === 'audit') {
        requireCentral($db, (int) $user['id']);
    }
    $allowed = $screen === 'review' ? ['GET', 'POST'] : ['GET'];
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    if ($screen === 'review') {
        $latestRevision = latestApplicationRevision($db,$application);
        $reviewedRevision = (int)$latestRevision['revision'];
        $revisionView = $_GET['revision'] ?? '';
        if (!is_string($revisionView)) {
            $screen = 'not-found';
            throw new AdmissionProblem('Nomor revisi tidak valid.',422);
        }
        if ($revisionView !== '') {
            $number = filter_var($revisionView,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($number === false) {
                $screen = 'not-found';
                throw new AdmissionProblem('Nomor revisi tidak valid.',422);
            }
            $statement = $db->prepare('SELECT * FROM application_revisions WHERE application_id=? AND revision=?');
            $statement->execute([$application['id'],$number]);
            $latestRevision = $number === 0 ? ['revision'=>0,'data_json'=>$application['data_json'],
                'documents_json'=>admissionJson(array_map(fn(array $d): string=>$d['id'],applicationDocuments($db,$application['id'])))]
                : ($statement->fetch() ?: throw new AdmissionProblem('Revisi tidak ditemukan.',404));
        }
        $data = admissionData($latestRevision['data_json']);
        $period = admissionPresentation(admissionPeriod($db, $application['period_id']));
        $period['configuration'] = admissionData($application['rule_snapshot_json']);
        $period = admissionPresentation($period);
        $documents = revisionDocuments($db,$application['id'],$latestRevision['documents_json']);
        $checklistItems = reviewChecklistItems($application,$documents);
        $statement = $db->prepare('SELECT checklist_json,version FROM detailed_reviews WHERE application_id=?');
        $statement->execute([$application['id']]);
        $currentReview = $statement->fetch();
        $checklistValues = admissionData($currentReview['checklist_json'] ?? '{}');
        $reviewFormVersion = $currentReview ? (int)$currentReview['version'] : reviewVersion($db,$application['id']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validCsrf()) {
                throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman.', 419);
            }
            if (input('action') === 'assign') {
                $reviewer = filter_var(input('reviewer_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $version = filter_var(input('assignment_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($reviewer === false || $version === false) {
                    throw new AdmissionProblem('Penugasan/versi tidak valid. Muat ulang peserta.', 409);
                }
                assignVerifier($db, $user, $application['id'], $reviewer, $version, input('assignment_note'));
                flash('Penugasan verifikator tersimpan.');
            } else {
                if ($revisionView !== '') {
                    throw new AdmissionProblem('Versi arsip hanya baca. Kembali ke revisi terbaru untuk memeriksa.',409);
                }
                if (!in_array(input('action'), ['', 'verify','save-checklist','request-correction'], true)) {
                    throw new AdmissionProblem('Aksi pemeriksaan tidak valid.', 422);
                }
                $version = filter_var(input('verification_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($version === false) {
                    throw new AdmissionProblem('Versi verifikasi tidak valid. Muat ulang halaman.', 409);
                }
                $reviewFormVersion = $version;
                $checklistValues = is_array($_POST['checklist'] ?? null) ? $_POST['checklist'] : [];
                $fieldReasons = $_POST['correction_fields'] ?? [];
                $docReasons = $_POST['correction_documents'] ?? [];
                if (is_array($fieldReasons)) $fieldReasons = array_filter($fieldReasons,fn(mixed $v): bool=> !is_string($v) || trim($v)!=='');
                if (is_array($docReasons)) $docReasons = array_filter($docReasons,fn(mixed $v): bool=> !is_string($v) || trim($v)!=='');
                saveDetailedReview($db,(int)$user['id'],$application['id'],$version,input('action') ?: 'verify',
                    $decision,$note,$checklistValues,$fieldReasons,$docReasons);
                flash(input('action') === 'save-checklist' ? 'Checklist tersimpan.' : 'Keputusan/permintaan tersimpan dan wali mendapat notifikasi in-app. Ini bukan keputusan penerimaan.');
            }
            redirect($path);
        }
    }
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors['form'] = $exception->getMessage();
    if (in_array($exception->status, [403, 404, 405], true)) {
        $screen = 'not-found';
    }
}

if ($adminAllowed) {
    [$periodScope, $periodParams] = staffScope($user, 'l.school_id');
    $statement = $db->prepare('SELECT p.*, MIN(COALESCE(v.enabled, 1), COALESCE(s.enabled, 1)) AS enabled FROM admission_periods p
        LEFT JOIN admission_period_availability v ON v.period_id = p.id LEFT JOIN period_school_links l ON l.period_id=p.id
        LEFT JOIN master_schools s ON s.id=l.school_id WHERE (' . $periodScope . ') ORDER BY p.school COLLATE NOCASE');
    $statement->execute($periodParams);
    $periods = $statement->fetchAll();
    foreach ($periods as &$row) {
        if (!operationalPeriodReady($db, $row['id'])) {
            $row['enabled'] = 0;
        }
    }
    unset($row);
    [$applicationScope, $scopeParams] = staffScope($user, 'l.school_id', 'a.id');
    if ($screen === 'dashboard') {
        $statement = $db->prepare("SELECT COUNT(*) AS total, SUM(a.status = 'draft') AS drafts,
            SUM(a.status = 'submitted') AS submitted,
            SUM(a.status = 'submitted' AND v.status IS NULL) AS pending,
            SUM(v.status = 'valid') AS valid, SUM(v.status = 'needs_correction') AS needs_correction,
            SUM(v.status = 'invalid') AS invalid FROM applications a
            JOIN period_school_links l ON l.period_id=a.period_id
            LEFT JOIN application_verifications v ON v.application_id = a.id WHERE ($applicationScope)");
        $statement->execute($scopeParams);
        $stats = $statement->fetch();
    }
    if ($screen === 'review' && $application) {
        $statement = $db->prepare('SELECT va.*,u.name AS reviewer_name FROM verification_assignments va
            LEFT JOIN users u ON u.id=va.reviewer_id WHERE va.application_id=?');
        $statement->execute([$application['id']]);
        $assignment = $statement->fetch() ?: null;
        $statement = $db->prepare('SELECT h.*,u.name AS actor_name,previous.name AS previous_name,reviewer.name AS reviewer_name
            FROM assignment_history h JOIN users u ON u.id=h.actor_id
            LEFT JOIN users previous ON previous.id=h.previous_reviewer_id LEFT JOIN users reviewer ON reviewer.id=h.reviewer_id
            WHERE h.application_id=? ORDER BY h.id DESC LIMIT 50');
        $statement->execute([$application['id']]);
        $assignmentHistory = $statement->fetchAll();
        $duplicatePeers = queueDuplicatePeers($db, $user, $application['id']);
        $statement = $db->prepare('SELECT h.*,u.name AS reviewer_name FROM detailed_review_history h JOIN users u ON u.id=h.reviewer_id
            WHERE h.application_id=? ORDER BY h.id DESC LIMIT 50');
        $statement->execute([$application['id']]);
        $reviewHistory = $statement->fetchAll();
        $statement = $db->prepare('SELECT revision,submitted_at FROM application_revisions WHERE application_id=? ORDER BY revision DESC');
        $statement->execute([$application['id']]);
        $revisions = $statement->fetchAll();
        $statement = $db->prepare('SELECT * FROM correction_requests WHERE application_id=? ORDER BY created_at DESC,id LIMIT 50');
        $statement->execute([$application['id']]);
        $correctionRequests = $statement->fetchAll();
        if (in_array($user['role'], ['central_admin', 'school_admin'], true)) {
            $statement = $db->prepare("SELECT u.id,u.name FROM staff_accounts s JOIN users u ON u.id=s.user_id
                JOIN staff_schools ss ON ss.user_id=s.user_id JOIN period_school_links l ON l.school_id=ss.school_id
                WHERE s.enabled=1 AND s.role='verifier' AND l.period_id=? ORDER BY u.name,u.id");
            $statement->execute([$application['period_id']]);
            $reviewers = $statement->fetchAll();
        }
        $verification = applicationVerification($db, $application['id']);
        $statement = $db->prepare('SELECT h.*, u.name AS reviewer_name FROM verification_history h JOIN users u ON u.id = h.reviewer_id
            WHERE h.application_id = ? ORDER BY h.id DESC LIMIT 50');
        $statement->execute([$application['id']]);
        $history = $statement->fetchAll();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $decision = $verification['status'] ?? '';
            $note = $verification['note'] ?? '';
        }
    }
    if ($screen === 'audit') {
        $rows = $db->query('SELECT e.action, e.created_at, u.name FROM audit_events e LEFT JOIN users u ON u.id = e.user_id
            ORDER BY e.id DESC LIMIT 100')->fetchAll();
    }
}
$notice = takeFlash();
require __DIR__ . '/views/admin.php';
