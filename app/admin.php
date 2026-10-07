<?php
declare(strict_types=1);
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/corrections.php';

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

function adminApplication(PDO $db, string $id, array $user): array
{
    authorizeStaffApplication($db, $user, $id);
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

function verifyApplication(PDO $db, int $reviewerId, string $id, int $expectedVersion, string $status, string $note, mixed $checklist = []): void
{
    saveDetailedReview($db,$reviewerId,$id,$expectedVersion,'verify',$status,$note,$checklist);
}

function applicationVerification(PDO $db, string $id): ?array
{
    $statement = $db->prepare('SELECT * FROM application_verifications WHERE application_id = ?');
    $statement->execute([$id]);
    $verification = $statement->fetch() ?: null;
    if ($verification) {
        $verification['version'] = reviewVersion($db,$id);
    }
    return $verification;
}
