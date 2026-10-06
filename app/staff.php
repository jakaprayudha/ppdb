<?php
declare(strict_types=1);

function isStaff(array $user): bool
{
    return in_array($user['role'], ['central_admin', 'school_admin', 'verifier'], true);
}

function staffRoleLabel(string $role): string
{
    return match ($role) {
        'central_admin' => 'Admin pusat',
        'school_admin' => 'Admin sekolah',
        'verifier' => 'Verifikator',
        default => throw new RuntimeException('Peran staf tidak dikenal.'),
    };
}

function requireCentral(PDO $db, int $actor): void
{
    $statement = $db->prepare('SELECT 1 FROM admin_accounts WHERE user_id = ?');
    $statement->execute([$actor]);
    if (!$statement->fetchColumn()) {
        throw new AdmissionProblem('Hanya admin pusat yang dapat mengelola akun dan master.', 403);
    }
}

function staffSchoolIds(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT school_id FROM staff_schools WHERE user_id = ? ORDER BY school_id');
    $statement->execute([$id]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function staffIdentity(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT u.id, COALESCE(a.role,s.role,u.role) AS role, COALESCE(s.enabled,1) AS enabled
        FROM users u LEFT JOIN admin_accounts a ON a.user_id=u.id LEFT JOIN staff_accounts s ON s.user_id=u.id WHERE u.id=?');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user || !isStaff($user) || !(int) $user['enabled']) {
        throw new AdmissionProblem('Akses staf tidak aktif.', 403);
    }
    return $user;
}

function staffScope(array $user, string $schoolExpression, ?string $applicationExpression = null): array
{
    if ($user['role'] === 'central_admin') {
        return ['1 = 1', []];
    }
    $sql = "EXISTS(SELECT 1 FROM staff_accounts sa JOIN staff_schools ss ON ss.user_id = sa.user_id
        WHERE sa.user_id = ? AND sa.enabled = 1 AND ss.school_id = $schoolExpression)";
    $parameters = [(int) $user['id']];
    if ($user['role'] === 'verifier' && $applicationExpression !== null) {
        $sql .= " AND EXISTS(SELECT 1 FROM verification_assignments va WHERE va.application_id = $applicationExpression AND va.reviewer_id = ?)";
        $parameters[] = (int) $user['id'];
    }
    if (!isStaff($user)) {
        return ['0 = 1', []];
    }
    return [$sql, $parameters];
}

function authorizeStaffApplication(PDO $db, array $user, string $id): void
{
    [$scope, $parameters] = staffScope($user, 'l.school_id', 'a.id');
    $statement = $db->prepare("SELECT 1 FROM applications a JOIN period_school_links l ON l.period_id = a.period_id
        WHERE a.id = ? AND a.status = 'submitted' AND ($scope)");
    $statement->execute([$id, ...$parameters]);
    if (!$statement->fetchColumn()) {
        throw new AdmissionProblem('Pendaftaran terkirim tidak ditemukan atau di luar penugasan Anda.', 404);
    }
}

function validatedStaffSchools(PDO $db, mixed $values): array
{
    if (!is_array($values) || !$values || count($values) > 200) {
        throw new AdmissionProblem('Pilih minimal satu sekolah, maksimal 200.', 422);
    }
    $statement = $db->prepare('SELECT 1 FROM master_schools WHERE id = ?');
    foreach ($values as $id) {
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new AdmissionProblem('Pilihan sekolah tidak valid.', 422);
        }
        $statement->execute([$id]);
        if (!$statement->fetchColumn()) {
            throw new AdmissionProblem('Sekolah tidak ditemukan. Muat ulang pilihan.', 422);
        }
    }
    return array_values(array_unique($values));
}

function validatedStaffRole(string $role): string
{
    if (!in_array($role, ['school_admin', 'verifier'], true)) {
        throw new AdmissionProblem('Pilih peran admin sekolah atau verifikator.', 422);
    }
    return $role;
}

function inviteStaff(PDO $db, array $config, int $actor, array $values): void
{
    $token = bin2hex(random_bytes(32));
    $id = bin2hex(random_bytes(16));
    $email = normalizedEmail($values['email']);
    $name = trim($values['name']);
    if (!validEmail($email) || mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        throw new AdmissionProblem('Isi nama 2–100 karakter dan email yang valid.', 422);
    }
    admissionTransaction($db, function () use ($db, $config, $actor, $values, $token, $id, $email, $name): void {
        requireCentral($db, $actor);
        $role = validatedStaffRole($values['role']);
        $schools = validatedStaffSchools($db, $values['schools']);
        $existing = $db->prepare('SELECT 1 FROM users WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetchColumn()) {
            throw new AdmissionProblem('Email sudah terdaftar. Akun wali/admin lama tidak dipromosikan melalui undangan; gunakan email staf tersendiri.', 409);
        }
        $existing = $db->prepare('SELECT 1 FROM staff_invitations WHERE email = ? AND used_at IS NULL AND cancelled_at IS NULL AND expires_at > ?');
        $existing->execute([$email, time()]);
        if ($existing->fetchColumn()) {
            throw new AdmissionProblem('Undangan aktif sudah ada. Batalkan dahulu jika perlu menerbitkan ulang.', 409);
        }
        $db->prepare('INSERT INTO staff_invitations(id,email,name,role,can_approve,token_hash,expires_at,created_by,created_at)
            VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id, $email, $name, $role, $values['can_approve'], hash('sha256', $token), time() + 86400, $actor, time()]);
        $insert = $db->prepare('INSERT INTO invitation_schools(invitation_id,school_id) VALUES(?,?)');
        foreach ($schools as $school) {
            $insert->execute([$id, $school]);
        }
        sendAccountEmail($config, $email, 'Undangan staf PPDB', "Anda diundang sebagai " . staffRoleLabel($role) . ".\n"
            . "Buka tautan berikut dalam 24 jam untuk membuat password sendiri dan memverifikasi email:\n"
            . $config['base_url'] . '/staff/accept?token=' . $token . "\n\nAutentikasi dua faktor opsional dan dapat diaktifkan melalui pengaturan profil akun.\n"
            . "Jika undangan tidak sesuai, abaikan dan hubungi pengelola.\n");
        audit($db, 'admin.staff_invited:' . $id, $actor);
    });
}

function findStaffInvitation(PDO $db, string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }
    $statement = $db->prepare('SELECT * FROM staff_invitations WHERE token_hash = ? AND used_at IS NULL AND cancelled_at IS NULL AND expires_at > ?');
    $statement->execute([hash('sha256', $token), time()]);
    return $statement->fetch() ?: null;
}

function acceptStaffInvitation(PDO $db, string $token, string $password, string $confirmation): void
{
    if ($message = passwordError($password, $confirmation)) {
        throw new AdmissionProblem($message, 422);
    }
    if (input('privacy') !== '1') {
        throw new AdmissionProblem('Baca dan setujui pemberitahuan privasi sebelum aktivasi.', 422);
    }
    admissionTransaction($db, function () use ($db, $token, $password): void {
        $invite = findStaffInvitation($db, $token);
        if (!$invite) {
            throw new AdmissionProblem('Undangan tidak valid, dibatalkan, digunakan atau kedaluwarsa.', 410);
        }
        $schoolQuery = $db->prepare('SELECT school_id FROM invitation_schools WHERE invitation_id = ?');
        $schoolQuery->execute([$invite['id']]);
        $schools = validatedStaffSchools($db, $schoolQuery->fetchAll(PDO::FETCH_COLUMN));
        $existing = $db->prepare('SELECT 1 FROM users WHERE email = ?');
        $existing->execute([$invite['email']]);
        if ($existing->fetchColumn()) {
            throw new AdmissionProblem('Email sudah digunakan akun lain. Undangan tidak mempromosikan akun yang ada.', 409);
        }
        $db->prepare('INSERT INTO users(name,email,password_hash,privacy_acknowledged_at,created_at) VALUES(?,?,?,?,?)')
            ->execute([$invite['name'], $invite['email'], password_hash($password, PASSWORD_DEFAULT), time(), time()]);
        $userId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO staff_accounts(user_id,role,can_approve,created_at) VALUES(?,?,?,?)')
            ->execute([$userId, $invite['role'], $invite['can_approve'], time()]);
        $insert = $db->prepare('INSERT INTO staff_schools(user_id,school_id) VALUES(?,?)');
        foreach ($schools as $school) {
            $insert->execute([$userId, $school]);
        }
        $db->prepare('INSERT INTO account_security(user_id,email_verified_at) VALUES(?,?)')->execute([$userId, time()]);
        $db->prepare('UPDATE staff_invitations SET used_at = ? WHERE id = ?')->execute([time(), $invite['id']]);
        audit($db, 'auth.staff_invitation_accepted:' . $invite['id'], $userId);
    });
}

function updateStaff(PDO $db, int $actor, int $id, int $version, string $role, array $schools, int $enabled, int $approve): void
{
    admissionTransaction($db, function () use ($db, $actor, $id, $version, $role, $schools, $enabled, $approve): void {
        requireCentral($db, $actor);
        $role = validatedStaffRole($role);
        $schools = validatedStaffSchools($db, $schools);
        $statement = $db->prepare('SELECT * FROM staff_accounts WHERE user_id = ?');
        $statement->execute([$id]);
        $staff = $statement->fetch();
        if (!$staff) {
            throw new AdmissionProblem('Akun staf tidak ditemukan; akun pusat tidak dapat diubah lewat formulir ini.', 404);
        }
        if ((int) $staff['version'] !== $version) {
            throw new AdmissionProblem('Penugasan akun sudah berubah. Muat ulang sebelum menyimpan.', 409);
        }
        $db->prepare('UPDATE staff_accounts SET role=?,enabled=?,can_approve=?,version=version+1 WHERE user_id=?')
            ->execute([$role, $enabled, $approve, $id]);
        $db->prepare('DELETE FROM staff_schools WHERE user_id=?')->execute([$id]);
        $insert = $db->prepare('INSERT INTO staff_schools(user_id,school_id) VALUES(?,?)');
        foreach ($schools as $school) {
            $insert->execute([$id, $school]);
        }
        $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$id]);
        $db->prepare('UPDATE verification_assignments SET reviewer_id=NULL,version=version+1,assigned_by=?,updated_at=?
            WHERE reviewer_id=?')->execute([$actor, time(), $id]);
        audit($db, 'admin.staff_updated:' . $id, $actor);
    });
}

function assignVerifier(PDO $db, array $actor, string $applicationId, int $reviewer, int $version): void
{
    admissionTransaction($db, function () use ($db, $actor, $applicationId, $reviewer, $version): void {
        $actor = staffIdentity($db, (int) $actor['id']);
        if (!in_array($actor['role'], ['central_admin', 'school_admin'], true)) {
            throw new AdmissionProblem('Hanya admin pusat/sekolah yang dapat menugaskan verifikator.', 403);
        }
        authorizeStaffApplication($db, $actor, $applicationId);
        $statement = $db->prepare('SELECT l.school_id FROM applications a JOIN period_school_links l ON l.period_id=a.period_id WHERE a.id=?');
        $statement->execute([$applicationId]);
        $schoolId = $statement->fetchColumn();
        if ($reviewer !== 0) {
            $statement = $db->prepare("SELECT 1 FROM staff_accounts s JOIN staff_schools ss ON ss.user_id=s.user_id
                WHERE s.user_id=? AND s.enabled=1 AND s.role='verifier' AND ss.school_id=?");
            $statement->execute([$reviewer, $schoolId]);
            if (!$statement->fetchColumn()) {
                throw new AdmissionProblem('Pilih verifikator aktif yang ditugaskan ke sekolah peserta.', 422);
            }
        }
        $statement = $db->prepare('SELECT version FROM verification_assignments WHERE application_id=?');
        $statement->execute([$applicationId]);
        if ((int) $statement->fetchColumn() !== $version) {
            throw new AdmissionProblem('Penugasan sudah berubah. Muat ulang peserta.', 409);
        }
        $db->prepare('INSERT INTO verification_assignments(application_id,reviewer_id,assigned_by,updated_at) VALUES(?,?,?,?)
            ON CONFLICT(application_id) DO UPDATE SET reviewer_id=excluded.reviewer_id,assigned_by=excluded.assigned_by,
            updated_at=excluded.updated_at,version=verification_assignments.version+1')
            ->execute([$applicationId, $reviewer ?: null, $actor['id'], time()]);
        applicationEvent($db, $applicationId, (int) $actor['id'], 'verifier_assigned');
        audit($db, 'admin.verifier_assigned:' . $applicationId . ':' . $reviewer, (int) $actor['id']);
    });
}
