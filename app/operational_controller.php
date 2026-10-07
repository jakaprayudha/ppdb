<?php
declare(strict_types=1);
$screen = 'operational';
$errors = [];
$rows = [];
$periods = [];
$years = [];
$history = [];
$item = null;
$source = null;
$values = [];
$total = 0;
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$page = filter_var(is_string($_GET['page'] ?? null) ? $_GET['page'] : '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
$type = str_starts_with($path, '/admin/master-data/years') ? 'years' : 'rules';
$base = str_starts_with($path, '/admin/rule-approvals') ? '/admin/rule-approvals' : '/admin/master-data/' . $type;
$mode = 'list';
$id = null;
$adminAllowed = isStaff($user) && $config['environment'] === 'development';
$approverPortal = $base === '/admin/rule-approvals';

try {
    if (!$adminAllowed) {
        throw new AdmissionProblem('Master operasional belum dibuka pada produksi atau akses staf tidak aktif.', 403);
    }
    if (!$approverPortal) {
        requireCentral($db, (int) $user['id']);
    } else {
        $statement = $db->prepare('SELECT 1 FROM staff_accounts WHERE user_id=? AND enabled=1 AND can_approve=1');
        $statement->execute([$user['id']]);
        if (!$statement->fetchColumn()) {
            throw new AdmissionProblem('Akses approver sekolah diperlukan.', 403);
        }
    }
    if (!$page) {
        throw new AdmissionProblem('Nomor halaman tidak valid.', 422);
    }
    if (!preg_match('~\A' . preg_quote($base, '~') . '(?:/(new|[a-f0-9]{32})(?:/(edit|delete))?)?\z~', $path, $matches)) {
        throw new AdmissionProblem('Halaman operasional tidak ditemukan.', 404);
    }
    $id = $matches[1] ?? null;
    $mode = $id === 'new' ? 'edit' : ($matches[2] ?? ($id ? 'detail' : 'list'));
    if ($id === 'new') {
        $id = null;
    }
    if (($approverPortal && $mode === 'edit') || ($type === 'rules' && $mode === 'delete')) {
        throw new AdmissionProblem('Paket tidak dapat diedit/dihapus melalui halaman ini.', 403);
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','POST'], true) || ($mode === 'list' && $method !== 'GET')) {
        header('Allow: ' . ($mode === 'list' ? 'GET' : 'GET, POST'));
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    if ($method === 'POST' && !validCsrf()) {
        throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang.', 419);
    }
    if ($id) {
        $item = $type === 'years' ? academicYear($db, $id) : operationalPack($db, $id, $user);
    }
    $years = $db->query('SELECT * FROM academic_years ORDER BY label DESC,id')->fetchAll();
    if ($type === 'years') {
        $values = $item ?? ['label' => '', 'starts_on' => '', 'ends_on' => ''];
        if ($method === 'POST' && $mode === 'edit') {
            foreach (['label','starts_on','ends_on'] as $key) {
                $values[$key] = input($key);
            }
            $saved = saveAcademicYear($db, (int) $user['id'], $id, $id ? operationalInteger(input('version'), 'Versi', 1) : null, $values);
            flash('Master tahun ajaran tersimpan.');
            redirect($base . '/' . $saved);
        } elseif ($method === 'POST') {
            academicYearAction($db, (int) $user['id'], $id, operationalInteger(input('version'), 'Versi', 1), $mode === 'delete' ? 'delete' : input('action'));
            flash('Master tahun ajaran diperbarui. Arsip tahun tidak mengubah data/jadwal periode yang telah terbit.');
            redirect($base . '?' . http_build_query(['q'=>$query,'page'=>$page]));
        }
    } else {
        if ($mode === 'edit') {
            if ($item && !in_array($item['status'], ['draft','returned'], true)) {
                throw new AdmissionProblem('Paket dibekukan; gunakan versi baru setelah versi kerja selesai.', 409);
            }
            $copyId = is_string($_GET['copy'] ?? null) ? $_GET['copy'] : '';
            $copy = $copyId !== '' && !$item ? operationalPack($db, $copyId, $user) : null;
            $periodId = $item['period_id'] ?? ($copy['period_id'] ?? (is_string($_GET['period'] ?? null) ? $_GET['period'] : ''));
            if ($method === 'POST') {
                $periodId = input('period_id');
            }
            if ($periodId === '') {
                if ($method === 'POST') {
                    throw new AdmissionProblem('Pilih periode sumber sebelum menyimpan paket.', 422);
                }
                $mode = 'choose-period';
            } else {
                $source = operationalSource($db, $periodId);
                $matchedYear = array_values(array_filter($years, fn(array $year): bool => $year['label'] === $source['configuration']['academic_year']));
                $values = $item || $copy ? admissionData(($item ?? $copy)['payload_json']) : [
                    'timezone' => $source['configuration']['timezone'], 'capacity' => '', 'class_limit' => '',
                    'classes' => [['name' => '', 'seats' => '']], 'quotas' => array_fill_keys(array_column($source['configuration']['pathways'], 'code'), ''),
                    'schedule' => array_fill_keys(array_keys(operationalStages()), ['start' => '', 'end' => '']), 'juknis' => [],
                ];
                $values['period_id'] = $periodId;
                $values['year_id'] = ($item ?? $copy)['year_id'] ?? ($matchedYear[0]['id'] ?? '');
                $values['reason'] = $item['reason'] ?? '';
                if (!$item && !$copy) {
                    $values['schedule']['registration'] = ['start' => $source['configuration']['opens_at'], 'end' => $source['configuration']['closes_at']];
                }
                if ($method === 'POST') {
                    foreach (['period_id','year_id','reason','timezone','capacity','class_limit'] as $key) {
                        $values[$key] = input($key);
                    }
                    foreach (['classes','quotas','schedule','juknis'] as $key) {
                        $raw = $_POST[$key] ?? null;
                        if (!is_array($raw)) {
                            throw new AdmissionProblem("Isian $key tidak valid.", 422);
                        }
                        $values[$key] = $raw;
                    }
                    if (input('action') === 'add-class') {
                        if (count($values['classes']) >= 100) {
                            throw new AdmissionProblem('Maksimal 100 rombel.', 422);
                        }
                        $values['classes'][] = ['name'=>'', 'seats'=>''];
                    } elseif (isset($_POST['remove_class'])) {
                        $index = operationalInteger(input('remove_class'), 'Indeks rombel', 0, 99);
                        if (count($values['classes']) <= 1 || !isset($values['classes'][$index])) {
                            throw new AdmissionProblem('Minimal satu rombel; pilih baris yang valid.', 422);
                        }
                        unset($values['classes'][$index]);
                        $values['classes'] = array_values($values['classes']);
                    } else {
                        $saved = saveOperationalPack($db, (int) $user['id'], $id, $id ? operationalInteger(input('version'), 'Versi', 1) : null,
                            $values['period_id'], $values['year_id'], $values, $values['reason']);
                        flash('Draf paket tersimpan. Tinjau ringkasan sebelum mengajukan persetujuan.');
                        redirect($base . '/' . $saved);
                    }
                }
            }
        } elseif ($id) {
            $source = admissionData($item['source_json']);
            $values = admissionData($item['payload_json']);
            if ($method === 'POST') {
                operationalPackAction($db, $user, $id, operationalInteger(input('version'), 'Versi', 1), input('action'), input('note'));
                flash('Status paket aturan diperbarui. Penerbitan tidak membuka produksi atau mengaktifkan katalog otomatis.');
                redirect($base . '/' . $id);
            }
        }
    }
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors['form'] = $exception->getMessage();
    if (in_array($exception->status, [403,404,405], true)) {
        $screen = 'not-found';
    }
}

if ($adminAllowed && $screen === 'operational') {
    if ($type === 'rules' && $item && $mode === 'detail') {
        $statement = $db->prepare('SELECT e.*,u.name AS actor_name FROM operational_rule_events e JOIN users u ON u.id=e.actor_id
            WHERE e.pack_id=? ORDER BY e.id DESC');
        $statement->execute([$id]);
        $history = $statement->fetchAll();
    }
    if ($user['role'] === 'central_admin') {
        $periods = $db->query('SELECT id,school,academic_year,code FROM admission_periods ORDER BY school COLLATE NOCASE,academic_year DESC,id')->fetchAll();
    }
    if ($mode === 'list' && $page) {
        if ($type === 'years') {
            $from = ' FROM academic_years y WHERE instr(y.label,?)>0';
            $parameters = [$query];
            $select = 'SELECT y.*, (SELECT COUNT(*) FROM admission_periods p WHERE p.academic_year=y.label) AS periods,
                (SELECT COUNT(*) FROM operational_rule_packs o WHERE o.year_id=y.id) AS packs';
            $order = ' ORDER BY y.label DESC,y.id';
        } else {
            $conditions = ["instr(lower(p.school || ' ' || p.code || ' ' || p.academic_year),lower(?))>0"];
            $parameters = [$query];
            if ($approverPortal) {
                $conditions[] = 'EXISTS(SELECT 1 FROM staff_schools ss JOIN staff_accounts sa ON sa.user_id=ss.user_id
                    WHERE ss.user_id=? AND ss.school_id=o.school_id AND sa.enabled=1 AND sa.can_approve=1)';
                $parameters[] = $user['id'];
            }
            if ($filter !== '') {
                if (!in_array($filter, ['draft','pending','returned','approved','published','superseded'], true)) {
                    http_response_code(422);
                    $errors['form'] = 'Filter status paket tidak valid.';
                    $conditions[] = '0=1';
                } else {
                    $conditions[] = 'o.status=?';
                    $parameters[] = $filter;
                }
            }
            $from = ' FROM operational_rule_packs o JOIN admission_periods p ON p.id=o.period_id WHERE ' . implode(' AND ', $conditions);
            $select = 'SELECT o.*,p.school,p.code,p.academic_year';
            $order = ' ORDER BY o.created_at DESC,o.id';
        }
        $statement = $db->prepare('SELECT COUNT(*)' . $from);
        $statement->execute($parameters);
        $total = (int) $statement->fetchColumn();
        $statement = $db->prepare($select . $from . $order . ' LIMIT 10 OFFSET ' . (($page - 1) * 10));
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
    }
}
$page = $page ?: 1;
$notice = takeFlash();
require __DIR__ . '/views/admin.php';
