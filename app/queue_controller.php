<?php
declare(strict_types=1);

$screen = 'applications';
$errors = [];
$rows = [];
$periods = [];
$queueReviewers = [];
$filters = ['q'=>'','period'=>'','year'=>'','pathway'=>'','status'=>'','queue'=>'','reviewer'=>'','duplicate'=>'','sort'=>'oldest','page'=>'1'];
$summary = ['total'=>0,'unassigned'=>0,'assigned'=>0,'overdue'=>0,'completed'=>0,'duplicates'=>0];
$total = 0;
$adminAllowed = isStaff($user) && $config['environment'] === 'development';
try {
    if (!$adminAllowed) {
        throw new AdmissionProblem('Antrean hanya tersedia untuk staf aktif pada development.', 403);
    }
    if (preg_match('~\A/admin/queue/([a-f0-9]{32})/claim\z~', $path, $matches)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new AdmissionProblem('Pengambilan tugas harus melalui POST.', 405);
        }
        if (!validCsrf()) {
            throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang antrean.', 419);
        }
        $version = operationalInteger(input('assignment_version'), 'Versi penugasan', 0);
        assignVerifier($db, $user, $matches[1], (int) $user['id'], $version, '', true);
        flash('Tugas berhasil diambil. Detail dan dokumen kini dapat diperiksa.');
        redirect('/admin/applications/' . $matches[1]);
    }
    if (!in_array($path, ['/admin/applications','/admin/queue'], true)) {
        throw new AdmissionProblem('Halaman antrean tidak ditemukan.', 404);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        throw new AdmissionProblem('Daftar antrean hanya menerima GET.', 405);
    }
    $periods = queueOptions($db, $user);
    $input = $_GET;
    if ($path === '/admin/queue' && !isset($input['queue'])) {
        $input['queue'] = $user['role'] === 'verifier' ? 'assigned' : 'unassigned';
    }
    $filters = queueFilters($input, $user, $periods);
    [$scope, $parameters] = staffScope($user, 'reviewer_schools.school_id');
    $statement = $db->prepare("SELECT DISTINCT u.id,u.name FROM staff_accounts s JOIN users u ON u.id=s.user_id
        JOIN staff_schools reviewer_schools ON reviewer_schools.user_id=s.user_id
        WHERE s.role='verifier' AND s.enabled=1 AND ($scope) ORDER BY u.name,u.id");
    $statement->execute($parameters);
    $queueReviewers = $statement->fetchAll();
    if ($filters['reviewer'] !== '' && !in_array((int) $filters['reviewer'], array_map('intval', array_column($queueReviewers, 'id')), true)) {
        throw new AdmissionProblem('Verifikator tidak tersedia dalam cakupan akses Anda.', 422);
    }
    ['rows'=>$rows,'total'=>$total,'summary'=>$summary] = queueData($db, $user, $filters);
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors['form'] = $exception->getMessage();
    if (in_array($exception->status, [403,404,405], true)) {
        $screen = 'not-found';
    }
}
$notice = takeFlash();
require __DIR__ . '/views/admin.php';
