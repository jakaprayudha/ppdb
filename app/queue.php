<?php
declare(strict_types=1);

function queueName(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? throw new RuntimeException('Nama peserta tidak valid.')));
}

function queueDeadline(?string $snapshot): ?int
{
    if ($snapshot === null) {
        return null;
    }
    $rules = admissionData($snapshot);
    $operational = $rules['operational']['data'] ?? null;
    if ($operational === null) {
        return null;
    }
    return operationalDate($operational['schedule']['verification']['end'], 'Tenggat verifikasi',
        'Y-m-d H:i:s', $operational['timezone'])->getTimestamp();
}

function queueOptions(PDO $db, array $user): array
{
    [$scope, $parameters] = staffScope($user, 'l.school_id');
    $statement = $db->prepare("SELECT p.id,p.school,p.code,p.academic_year,p.config_json FROM admission_periods p
        JOIN period_school_links l ON l.period_id=p.id WHERE ($scope) ORDER BY p.school,p.code");
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function queueFilters(array $input, array $user, array $periods): array
{
    $filters = [];
    foreach (['q','period','year','pathway','status','queue','reviewer','duplicate','sort','page'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) {
            throw new AdmissionProblem('Filter antrean tidak valid.', 422);
        }
        $filters[$key] = trim($input[$key] ?? '');
    }
    $filters['sort'] = $filters['sort'] === '' ? 'oldest' : $filters['sort'];
    $filters['page'] = $filters['page'] === '' ? '1' : $filters['page'];
    if (mb_strlen($filters['q']) > 200
        || !in_array($filters['status'], ['', 'pending','valid','needs_correction','invalid'], true)
        || !in_array($filters['queue'], ['', 'unassigned','assigned','overdue','completed','available'], true)
        || !in_array($filters['duplicate'], ['', 'potential'], true)
        || !in_array($filters['sort'], ['oldest','newest','name','number','deadline'], true)
        || !filter_var($filters['page'], FILTER_VALIDATE_INT, ['options' => ['min_range'=>1,'max_range'=>100000]])) {
        throw new AdmissionProblem('Filter, urutan atau nomor halaman antrean tidak valid.', 422);
    }
    $years = array_column($periods, 'academic_year');
    $pathways = [];
    foreach ($periods as $period) {
        $pathways = [...$pathways, ...array_column(admissionData($period['config_json'])['pathways'], 'code')];
    }
    if (($filters['period'] !== '' && !in_array($filters['period'], array_column($periods, 'id'), true))
        || ($filters['year'] !== '' && !in_array($filters['year'], $years, true))
        || ($filters['pathway'] !== '' && !in_array($filters['pathway'], $pathways, true))
        || ($filters['reviewer'] !== '' && !filter_var($filters['reviewer'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]))) {
        throw new AdmissionProblem('Pilihan filter tidak tersedia dalam cakupan akses Anda.', 422);
    }
    if (($filters['queue'] === 'available' && $user['role'] !== 'verifier')
        || ($user['role'] === 'verifier' && ($filters['reviewer'] !== '' || $filters['queue'] === 'unassigned'))
        || ($filters['queue'] === 'available' && ($filters['q'] !== '' || $filters['duplicate'] !== ''
            || $filters['sort'] === 'name' || !in_array($filters['status'], ['', 'pending'], true)))) {
        throw new AdmissionProblem('Antrean tugas kosong hanya memuat ringkasan tanpa pencarian identitas atau data duplikasi.', 422);
    }
    return $filters;
}

function queueSqliteFunctions(PDO $db): void
{
    $db->sqliteCreateFunction('queue_name', fn(?string $value): string => queueName($value ?? ''), 1, PDO::SQLITE_DETERMINISTIC);
    $db->sqliteCreateFunction('queue_deadline', 'queueDeadline', 1, PDO::SQLITE_DETERMINISTIC);
}

function queueDuplicateMatch(): string
{
    return "(COALESCE(json_extract(a.data_json,'$.nisn'),'')<>'' AND json_extract(a.data_json,'$.nisn')=json_extract(d.data_json,'$.nisn'))
        OR (queue_name(json_extract(a.data_json,'$.name'))<>'' AND queue_name(json_extract(a.data_json,'$.name'))=queue_name(json_extract(d.data_json,'$.name'))
            AND COALESCE(json_extract(a.data_json,'$.birth_date'),'')<>'' AND json_extract(a.data_json,'$.birth_date')=json_extract(d.data_json,'$.birth_date'))";
}

function queueDuplicatePeers(PDO $db, array $user, string $id): array
{
    authorizeStaffApplication($db, $user, $id);
    queueSqliteFunctions($db);
    [$scope, $parameters] = staffScope($user, 'dl.school_id', 'd.id');
    $match = queueDuplicateMatch();
    $source = queueApplicationSource();
    $statement = $db->prepare("SELECT d.id,d.registration_number,json_extract(d.data_json,'$.name') AS name,dp.school,
        CASE WHEN COALESCE(json_extract(a.data_json,'$.nisn'),'')<>'' AND json_extract(a.data_json,'$.nisn')=json_extract(d.data_json,'$.nisn')
            THEN 'NISN sama' ELSE 'Nama dan tanggal lahir sama' END AS reason
        FROM $source a JOIN admission_periods p ON p.id=a.period_id
        JOIN $source d ON d.id<>a.id AND d.status='submitted' JOIN admission_periods dp ON dp.id=d.period_id
        JOIN period_school_links dl ON dl.period_id=d.period_id
        WHERE a.id=? AND dp.academic_year=p.academic_year AND ($scope) AND ($match) ORDER BY d.submitted_at,d.id LIMIT 25");
    $statement->execute([$id,...$parameters]);
    return $statement->fetchAll();
}

function queueApplicationSource(): string
{
    return '(SELECT original.id,original.period_id,original.pathway,original.status,original.registration_number,
        original.submitted_at,original.rule_snapshot_json,COALESCE(revision.data_json,original.data_json) AS data_json,
        COALESCE(revision.revision,0) AS revision FROM applications original
        LEFT JOIN application_revisions revision ON revision.application_id=original.id AND revision.revision=
            (SELECT MAX(latest.revision) FROM application_revisions latest WHERE latest.application_id=original.id))';
}

function queueData(PDO $db, array $user, array $filters): array
{
    queueSqliteFunctions($db);
    $available = $filters['queue'] === 'available';
    [$scope, $parameters] = staffScope($user, 'l.school_id', $available ? null : 'a.id');
    [$duplicateScope, $duplicateParameters] = staffScope($user, 'dl.school_id', 'd.id');
    $source = queueApplicationSource();
    $duplicate = "EXISTS(SELECT 1 FROM $source d JOIN admission_periods dp ON dp.id=d.period_id
        JOIN period_school_links dl ON dl.period_id=d.period_id WHERE d.status='submitted' AND d.id<>a.id
        AND dp.academic_year=p.academic_year AND ($duplicateScope) AND (" . queueDuplicateMatch() . '))';
    $base = " FROM $source a JOIN admission_periods p ON p.id=a.period_id JOIN period_school_links l ON l.period_id=a.period_id
        LEFT JOIN application_verifications v ON v.application_id=a.id LEFT JOIN verification_assignments va ON va.application_id=a.id
        LEFT JOIN users ru ON ru.id=va.reviewer_id WHERE a.status='submitted' AND ($scope)";
    $select = "SELECT a.id,a.registration_number,a.pathway,a.period_id,a.submitted_at,a.revision,p.school,p.code,p.academic_year,p.timezone,
        v.status AS verification_status,va.reviewer_id,COALESCE(va.version,0) AS assignment_version,ru.name AS reviewer_name,
        queue_deadline(a.rule_snapshot_json) AS deadline," . ($available
            ? "NULL AS student_name,0 AS potential_duplicate"
            : "json_extract(a.data_json,'$.name') AS student_name,$duplicate AS potential_duplicate");
    if ($available) {
        $base .= ' AND va.reviewer_id IS NULL AND v.status IS NULL';
        $rowsParameters = $parameters;
    } else {
        $rowsParameters = [...$duplicateParameters, ...$parameters];
    }
    $db->exec('DROP TABLE IF EXISTS temp.queue_rows');
    $statement = $db->prepare('CREATE TEMP TABLE queue_rows AS ' . $select . $base);
    $statement->execute($rowsParameters);
    $where = ['1=1'];
    $values = [];
    foreach (['period'=>'period_id','year'=>'academic_year','pathway'=>'pathway','reviewer'=>'reviewer_id'] as $key=>$column) {
        if ($filters[$key] !== '') {
            $where[] = "$column=?";
            $values[] = $filters[$key];
        }
    }
    if ($filters['q'] !== '') {
        $where[] = '(instr(queue_name(student_name),queue_name(?))>0 OR instr(lower(registration_number),lower(?))>0)';
        array_push($values, $filters['q'], $filters['q']);
    }
    if ($filters['status'] !== '') {
        $where[] = $filters['status'] === 'pending' ? 'verification_status IS NULL' : 'verification_status=?';
        if ($filters['status'] !== 'pending') {
            $values[] = $filters['status'];
        }
    }
    if ($filters['duplicate'] !== '') {
        $where[] = 'potential_duplicate=1';
    }
    $common = implode(' AND ', $where);
    $statement = $db->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(verification_status IS NULL AND reviewer_id IS NULL),0) AS unassigned,
        COALESCE(SUM(verification_status IS NULL AND reviewer_id IS NOT NULL),0) AS assigned,
        COALESCE(SUM(verification_status IS NULL AND deadline IS NOT NULL AND deadline<CAST(? AS INTEGER)),0) AS overdue,
        COALESCE(SUM(verification_status IS NOT NULL),0) AS completed,
        COALESCE(SUM(potential_duplicate),0) AS duplicates FROM queue_rows WHERE $common");
    $now = time();
    $statement->execute([$now, ...$values]);
    $summary = $statement->fetch();
    $queueCondition = match ($filters['queue']) {
        'unassigned','available' => 'verification_status IS NULL AND reviewer_id IS NULL',
        'assigned' => 'verification_status IS NULL AND reviewer_id IS NOT NULL',
        'overdue' => 'verification_status IS NULL AND deadline IS NOT NULL AND deadline<' . $now,
        'completed' => 'verification_status IS NOT NULL',
        default => '1=1',
    };
    $where[] = '(' . $queueCondition . ')';
    $from = ' FROM queue_rows WHERE ' . implode(' AND ', $where);
    $statement = $db->prepare('SELECT COUNT(*)' . $from);
    $statement->execute($values);
    $total = (int) $statement->fetchColumn();
    $order = match ($filters['sort']) {
        'newest'=>'submitted_at DESC', 'name'=>'queue_name(student_name)', 'number'=>'registration_number',
        'deadline'=>'deadline IS NULL,deadline', default=>'submitted_at ASC',
    };
    $statement = $db->prepare('SELECT *' . $from . ' ORDER BY ' . $order . ',id LIMIT 25 OFFSET ' . (((int) $filters['page'] - 1) * 25));
    $statement->execute($values);
    return ['rows'=>$statement->fetchAll(),'total'=>$total,'summary'=>$summary];
}

function assignmentActionLabel(string $action): string
{
    return match ($action) {
        'assign'=>'Ditugaskan', 'claim'=>'Diambil verifikator', 'reassign'=>'Dialihkan',
        'release'=>'Dilepas', 'access_revoked'=>'Dilepas karena perubahan akses',
        default=>throw new RuntimeException('Aksi riwayat penugasan tidak dikenal.'),
    };
}
