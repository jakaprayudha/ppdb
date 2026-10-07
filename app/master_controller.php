<?php
declare(strict_types=1);
require __DIR__ . '/master_data.php';
require __DIR__ . '/admission_templates.php';

$adminAllowed = $user['role'] === 'central_admin' && $config['environment'] === 'development';
$screen = 'master';
$errors = [];
$periods = [];
$rows = [];
$total = 0;
$query = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
$type = 'periods';
$mode = 'list';
$item = null;
$formData = [];
$schools = [];
$pageInput = $_GET['page'] ?? '1';
$page = is_string($pageInput) ? filter_var($pageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) : false;
$page = $page === false ? 0 : $page;

try {
    if (!$adminAllowed) {
        throw new AdmissionProblem('Master data hanya tersedia untuk admin pusat pada development.', 403);
    }
    if ($path === '/admin/master-data') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            header('Allow: GET');
            throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
        }
        redirect('/admin/master-data/periods');
    }
    if (!preg_match('~\A/admin/master-data/(schools|periods)(?:/(new|[a-f0-9]{32})(?:/(edit|delete))?)?\z~', $path, $matches)) {
        throw new AdmissionProblem('Halaman master data tidak ditemukan.', 404);
    }
    $type = $matches[1];
    $id = $matches[2] ?? null;
    $mode = $id === 'new' ? 'edit' : ($matches[3] ?? ($id ? 'detail' : 'list'));
    if ($id === 'new') {
        $id = null;
    } elseif ($id) {
        $item = $type === 'schools' ? masterSchool($db, $id) : masterPeriod($db, $id);
    }
    if (!$page) {
        throw new AdmissionProblem('Nomor halaman tidak valid.', 422);
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true) || ($method === 'POST' && $mode === 'list')) {
        header('Allow: ' . ($mode === 'list' ? 'GET' : 'GET, POST'));
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    if ($method === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new AdmissionProblem('Isian master data terlalu besar. Kurangi panjang isian dan coba lagi.', 413);
    }
    $schools = $db->query('SELECT * FROM master_schools ORDER BY name COLLATE NOCASE')->fetchAll();
    if ($type === 'schools' && $mode === 'edit') {
        $formData = $item ?? ['npsn' => '', 'name' => '', 'level' => 'SMP', 'mode' => 'public_spmb',
            'province' => 'Sumatera Utara', 'city' => 'Kabupaten Serdang Bedagai', 'district' => '', 'address' => ''];
        if ($method === 'POST') {
            foreach (['npsn', 'name', 'level', 'mode', 'province', 'city', 'district', 'address'] as $key) {
                $formData[$key] = trim(input($key));
            }
        }
    }
    if ($type === 'periods' && $mode === 'edit') {
        $copy = is_string($_GET['copy'] ?? '') ? ($_GET['copy'] ?? '') : '';
        $source = $item ?? ($copy !== '' ? masterPeriod($db, $copy) : null);
        $formData = $source ? $source['configuration'] : admissionTemplate('negeri', 'SMP', new DateTimeImmutable());
        $formData['school_id'] = $source['school_id'] ?? '';
        if (!$item) {
            $formData['code'] = '';
            unset($formData['operational']);
        }
        if ($method === 'POST') {
            foreach (['code', 'organizer', 'academic_year', 'timezone', 'opens_at', 'closes_at',
                'privacy_notice', 'help_contact', 'rule_reference', 'admission_mode', 'school_id'] as $key) {
                $formData[$key] = trim(input($key));
            }
            $pathways = $_POST['pathways'] ?? null;
            if (!is_array($pathways) || count($pathways) < 1 || count($pathways) > 20) {
                throw new AdmissionProblem('Masukkan 1–20 jalur melalui editor.', 422);
            }
            $formData['pathways'] = [];
            foreach ($pathways as $route) {
                if (!is_array($route)) {
                    throw new AdmissionProblem('Isian jalur tidak valid.', 422);
                }
                $documents = $route['documents'] ?? [];
                if (!is_array($documents) || count($documents) > 10) {
                    throw new AdmissionProblem('Maksimal 10 dokumen per jalur.', 422);
                }
                $entry = [];
                foreach (['code', 'name', 'description'] as $key) {
                    if (!is_string($route[$key] ?? null)) {
                        throw new AdmissionProblem('Isian jalur tidak lengkap.', 422);
                    }
                    $entry[$key] = trim($route[$key]);
                }
                $entry['documents'] = [];
                foreach ($documents as $doc) {
                    if (!is_array($doc) || !is_string($doc['code'] ?? null) || !is_string($doc['label'] ?? null)) {
                        throw new AdmissionProblem('Isian dokumen tidak valid.', 422);
                    }
                    $entry['documents'][] = ['code' => trim($doc['code']), 'label' => trim($doc['label']),
                        'required' => ($doc['required'] ?? '') === '1'];
                }
                $formData['pathways'][] = $entry;
            }
        }
    }
    if ($method === 'POST') {
        if (!validCsrf()) {
            throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman.', 419);
        }
        if ($mode === 'edit') {
            if ($type === 'schools') {
                $saved = saveMasterSchool($db, $id, (int) $user['id'], $formData, $id ? masterVersion() : null);
            } else {
                $saved = saveMasterPeriod($db, $id, (int) $user['id'], $formData['school_id'], $formData, $id ? masterVersion() : null);
            }
            flash($type === 'schools' ? 'Master sekolah tersimpan.' : 'Periode tersimpan. Periode baru diarsipkan dahulu; aktifkan setelah persyaratan diperiksa.');
            redirect('/admin/master-data/' . $type . '/' . $saved);
        }
        if ($item) {
            $action = $mode === 'delete' ? 'delete' : input('action');
            masterAction($db, $type, $id, (int) $user['id'], masterVersion(), $action);
            flash($action === 'delete' ? 'Master data dihapus.' : 'Status arsip master data diperbarui.');
            $returnList = ($_GET['return_list'] ?? '') === '1';
            redirect('/admin/master-data/' . $type . ($returnList ? '?' . http_build_query(['q' => $query, 'page' => $page]) : ''));
        }
    }
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors['form'] = $exception->getMessage();
    if (in_array($exception->status, [403, 404, 405], true)) {
        $screen = 'not-found';
    }
}
if ($adminAllowed && $screen === 'master' && $mode === 'list' && $page > 0) {
    if ($type === 'schools') {
        $from = ' FROM master_schools s WHERE instr(lower(s.name || \' \' || COALESCE(s.npsn,\'\') || \' \' || s.district), lower(?)) > 0';
        $select = 'SELECT s.*, EXISTS(SELECT 1 FROM period_school_links l JOIN period_management m ON m.period_id=l.period_id
            WHERE l.school_id=s.id AND m.used=1) AS used, (SELECT COUNT(*) FROM period_school_links l WHERE l.school_id=s.id) AS periods';
        $order = ' ORDER BY s.name COLLATE NOCASE, s.id';
    } else {
        $from = ' FROM admission_periods p JOIN period_school_links l ON l.period_id=p.id JOIN master_schools s ON s.id=l.school_id JOIN period_management m ON m.period_id=p.id
            LEFT JOIN admission_period_availability v ON v.period_id=p.id
            WHERE instr(lower(p.school || \' \' || p.code || \' \' || p.academic_year), lower(?)) > 0';
        $select = 'SELECT p.*, l.school_id, m.used, m.version AS management_version, MIN(COALESCE(v.enabled,1),s.enabled) AS enabled';
        $order = ' ORDER BY p.school COLLATE NOCASE, p.academic_year DESC, p.id';
    }
    $statement = $db->prepare('SELECT COUNT(*)' . $from);
    $statement->execute([$query]);
    $total = (int) $statement->fetchColumn();
    $statement = $db->prepare($select . $from . $order . ' LIMIT 10 OFFSET ' . (($page - 1) * 10));
    $statement->execute([$query]);
    $rows = $statement->fetchAll();
    if ($type === 'periods') {
        foreach ($rows as &$row) {
            if (!operationalPeriodReady($db, $row['id'])) {
                $row['enabled'] = 0;
            }
        }
        unset($row);
    }
}
$notice = takeFlash();
require __DIR__ . '/views/admin.php';
