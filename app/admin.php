<?php
declare(strict_types=1);

function verificationLabel(?string $status): string
{
    return match ($status) {
        null, 'pending' => 'Menunggu verifikasi',
        'valid' => 'Valid',
        'needs_correction' => 'Perlu perbaikan',
        'invalid' => 'Tidak valid',
        default => throw new RuntimeException('Status verifikasi tidak dikenal.'),
    };
}

function adminApplication(PDO $db, string $id): array
{
    $statement = $db->prepare("SELECT a.*, p.school, p.academic_year, p.timezone, u.name AS guardian_account, u.email AS guardian_email
        FROM applications a JOIN admission_periods p ON p.id = a.period_id JOIN users u ON u.id = a.user_id
        WHERE a.id = ? AND a.status = 'submitted'");
    $statement->execute([$id]);
    $application = $statement->fetch();
    if (!$application) {
        throw new AdmissionProblem('Pendaftaran terkirim tidak ditemukan.', 404);
    }
    return $application;
}

function verifyApplication(PDO $db, int $reviewerId, string $id, int $expectedVersion, string $status, string $note): void
{
    $note = trim($note);
    if (!in_array($status, ['valid', 'needs_correction', 'invalid'], true)) {
        throw new AdmissionProblem('Pilih keputusan verifikasi yang valid.', 422);
    }
    if (mb_strlen($note) < 5 || mb_strlen($note) > 2000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
        throw new AdmissionProblem('Catatan wajib 5–2000 karakter dan aman ditampilkan kepada wali.', 422);
    }
    admissionTransaction($db, function () use ($db, $reviewerId, $id, $expectedVersion, $status, $note): void {
        $role = $db->prepare("SELECT 1 FROM admin_accounts WHERE user_id = ? AND role = 'central_admin'");
        $role->execute([$reviewerId]);
        if (!$role->fetchColumn()) {
            throw new AdmissionProblem('Akses admin pusat diperlukan.', 403);
        }
        adminApplication($db, $id);
        $statement = $db->prepare('SELECT version FROM application_verifications WHERE application_id = ?');
        $statement->execute([$id]);
        $version = (int) $statement->fetchColumn();
        if ($version !== $expectedVersion) {
            throw new AdmissionProblem('Verifikasi telah berubah di tab atau oleh admin lain. Muat ulang sebelum memutuskan.', 409);
        }
        $now = time();
        $db->prepare('INSERT INTO application_verifications(application_id, status, note, reviewer_id, version, updated_at)
            VALUES (?, ?, ?, ?, 1, ?) ON CONFLICT(application_id) DO UPDATE SET
            status = excluded.status, note = excluded.note, reviewer_id = excluded.reviewer_id,
            version = application_verifications.version + 1, updated_at = excluded.updated_at')
            ->execute([$id, $status, $note, $reviewerId, $now]);
        $db->prepare('INSERT INTO verification_history(application_id, status, note, reviewer_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $status, $note, $reviewerId, $now]);
        applicationEvent($db, $id, $reviewerId, 'verification_updated');
        audit($db, 'admin.verification_updated', $reviewerId);
    });
}

function applicationVerification(PDO $db, string $id): ?array
{
    $statement = $db->prepare('SELECT * FROM application_verifications WHERE application_id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}
