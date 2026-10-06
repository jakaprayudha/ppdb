<?php
declare(strict_types=1);

require __DIR__ . '/admissions.php';
require __DIR__ . '/admin.php';
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
$query = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
$schoolFilter = is_string($_GET['period'] ?? '') ? ($_GET['period'] ?? '') : '';
$statusFilter = is_string($_GET['status'] ?? '') ? ($_GET['status'] ?? '') : '';
$pageInput = $_GET['page'] ?? '1';
$page = is_string($pageInput) ? filter_var($pageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) : false;
$invalidPage = $page === false;
$page = $page === false ? 1 : $page;
$periods = [];
$rows = [];
$total = 0;
$stats = [];
$adminAllowed = $user['role'] === 'central_admin' && $config['environment'] === 'development';

try {
    if (!$adminAllowed) {
        throw new AdmissionProblem($user['role'] === 'central_admin'
            ? 'Portal admin belum dibuka pada produksi. MFA dan persetujuan operasional diperlukan.'
            : 'Halaman ini hanya untuk admin pusat.', 403);
    }
    $screens = ['/admin' => 'dashboard', '/admin/applications' => 'applications', '/admin/master-data' => 'master',
        '/admin/accounts' => 'accounts', '/admin/audit' => 'audit'];
    $screen = $screens[$path] ?? 'not-found';
    if (preg_match('~\A/admin/applications/([a-f0-9]{32})\z~', $path, $matches)) {
        $screen = 'review';
        $application = adminApplication($db, $matches[1]);
    }
    if ($screen === 'not-found') {
        throw new AdmissionProblem('Halaman tidak ditemukan.', 404);
    }
    if ($screen === 'applications' && $invalidPage) {
        throw new AdmissionProblem('Nomor halaman tidak valid.', 422);
    }
    $allowed = $screen === 'review' ? ['GET', 'POST'] : ['GET'];
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    if ($screen === 'review') {
        $data = admissionData($application['data_json']);
        $period = admissionPresentation(admissionPeriod($db, $application['period_id']));
        $period['configuration'] = admissionData($application['rule_snapshot_json']);
        $period = admissionPresentation($period);
        $documents = applicationDocuments($db, $application['id']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validCsrf()) {
                throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman.', 419);
            }
            $version = filter_var(input('verification_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($version === false) {
                throw new AdmissionProblem('Versi verifikasi tidak valid. Muat ulang halaman.', 409);
            }
            verifyApplication($db, (int) $user['id'], $application['id'], $version, $decision, $note);
            flash('Keputusan verifikasi tersimpan dan dapat dilihat wali. Ini bukan keputusan penerimaan.');
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
    $periods = $db->query('SELECT p.*, MIN(COALESCE(v.enabled, 1), COALESCE(s.enabled, 1)) AS enabled FROM admission_periods p
        LEFT JOIN admission_period_availability v ON v.period_id = p.id LEFT JOIN period_school_links l ON l.period_id=p.id
        LEFT JOIN master_schools s ON s.id=l.school_id ORDER BY p.school COLLATE NOCASE')->fetchAll();
    if ($screen === 'dashboard') {
        $stats = $db->query("SELECT COUNT(*) AS total, SUM(a.status = 'draft') AS drafts,
            SUM(a.status = 'submitted') AS submitted,
            SUM(a.status = 'submitted' AND v.status IS NULL) AS pending,
            SUM(v.status = 'valid') AS valid, SUM(v.status = 'needs_correction') AS needs_correction,
            SUM(v.status = 'invalid') AS invalid FROM applications a
            LEFT JOIN application_verifications v ON v.application_id = a.id")->fetch();
    }
    if ($screen === 'applications') {
        if ($invalidPage) {
            http_response_code(422);
            $errors['form'] = 'Nomor halaman tidak valid.';
        } elseif (!in_array($statusFilter, ['', 'pending', 'valid', 'needs_correction', 'invalid'], true)) {
            http_response_code(422);
            $errors['form'] = 'Filter verifikasi tidak valid.';
        } else {
            $where = ["a.status = 'submitted'"];
            $parameters = [];
            if ($query !== '') {
                $where[] = '(instr(lower(a.registration_number), lower(?)) > 0 OR instr(lower(a.data_json), lower(?)) > 0)';
                array_push($parameters, $query, $query);
            }
            if ($schoolFilter !== '') {
                $where[] = 'a.period_id = ?';
                $parameters[] = $schoolFilter;
            }
            if ($statusFilter === 'pending') {
                $where[] = 'v.status IS NULL';
            } elseif ($statusFilter !== '') {
                $where[] = 'v.status = ?';
                $parameters[] = $statusFilter;
            }
            $from = ' FROM applications a JOIN admission_periods p ON p.id = a.period_id
                LEFT JOIN application_verifications v ON v.application_id = a.id WHERE ' . implode(' AND ', $where);
            $statement = $db->prepare('SELECT COUNT(*)' . $from);
            $statement->execute($parameters);
            $total = (int) $statement->fetchColumn();
            $statement = $db->prepare('SELECT a.*, p.school, v.status AS verification_status' . $from
                . ' ORDER BY a.submitted_at DESC, a.id LIMIT 25 OFFSET ' . (($page - 1) * 25));
            $statement->execute($parameters);
            $rows = $statement->fetchAll();
        }
    }
    if ($screen === 'review' && $application) {
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
    if ($screen === 'accounts') {
        $rows = $db->query('SELECT u.name, u.email, a.role, a.created_at FROM admin_accounts a JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC')->fetchAll();
    }
    if ($screen === 'audit') {
        $rows = $db->query('SELECT e.action, e.created_at, u.name FROM audit_events e LEFT JOIN users u ON u.id = e.user_id
            ORDER BY e.id DESC LIMIT 100')->fetchAll();
    }
}
$notice = takeFlash();
require __DIR__ . '/views/admin.php';
