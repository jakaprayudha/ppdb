<?php
declare(strict_types=1);
$screen = 'accounts';
$errors = [];
$periods = [];
$rows = [];
$staffRows = [];
$invitations = [];
$schools = [];
$editStaff = null;
$total = 0;
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$page = filter_var(is_string($_GET['page'] ?? null) ? $_GET['page'] : '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
$adminAllowed = isStaff($user) && $config['environment'] === 'development';
$values = ['name' => input('name'), 'email' => input('email'), 'role' => input('role') ?: 'school_admin',
    'schools' => $_POST['schools'] ?? [], 'enabled' => input('enabled') === '1' ? 1 : 0, 'can_approve' => input('can_approve') === '1' ? 1 : 0];
try {
    if (!$adminAllowed) {
        throw new AdmissionProblem('Portal admin belum dibuka pada produksi atau akses tidak diizinkan.', 403);
    }
    requireCentral($db, (int) $user['id']);
    if (!$page) {
        throw new AdmissionProblem('Nomor halaman tidak valid.', 422);
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    $editId = null;
    $cancelId = null;
    if (preg_match('~\A/admin/accounts/([1-9][0-9]*)\z~', $path, $matches)) {
        $editId = (int) $matches[1];
        $statement = $db->prepare('SELECT u.id,u.name,u.email,s.* FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.id=?');
        $statement->execute([$editId]);
        $editStaff = $statement->fetch();
        if (!$editStaff) {
            throw new AdmissionProblem('Akun staf tidak ditemukan.', 404);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $values = $editStaff + ['schools' => staffSchoolIds($db, $editId)];
        }
    } elseif (preg_match('~\A/admin/accounts/invitations/([a-f0-9]{32})/cancel\z~', $path, $matches)) {
        $cancelId = $matches[1];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new AdmissionProblem('Gunakan formulir untuk membatalkan undangan.', 405);
        }
    } elseif ($path !== '/admin/accounts') {
        throw new AdmissionProblem('Halaman akun tidak ditemukan.', 404);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validCsrf()) {
            throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman.', 419);
        }
        if ($cancelId) {
            admissionTransaction($db, function () use ($db, $cancelId, $user): void {
                requireCentral($db, (int) $user['id']);
                $statement = $db->prepare('UPDATE staff_invitations SET cancelled_at=? WHERE id=? AND used_at IS NULL AND cancelled_at IS NULL');
                $statement->execute([time(), $cancelId]);
                if ($statement->rowCount() !== 1) {
                    throw new AdmissionProblem('Undangan sudah digunakan/dibatalkan atau tidak ditemukan. Muat ulang daftar.', 409);
                }
                audit($db, 'admin.staff_invitation_cancelled:' . $cancelId, (int) $user['id']);
            });
            flash('Undangan dibatalkan; tautan lama tidak dapat digunakan.');
        } elseif ($editId) {
            $version = filter_var(input('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$version) {
                throw new AdmissionProblem('Versi akun tidak valid. Muat ulang.', 409);
            }
            if (!is_array($values['schools'])) {
                throw new AdmissionProblem('Pilihan sekolah tidak valid.', 422);
            }
            updateStaff($db, (int) $user['id'], $editId, $version, $values['role'], $values['schools'], $values['enabled'], $values['can_approve']);
            flash('Akun dan akses sekolah diperbarui. Sesi staf dicabut dan penugasan peserta dilepas; tugaskan ulang bila diperlukan.');
        } else {
            if (!consumeRateLimit($db, 'staff-invite', (string) $user['id'], 30)) {
                throw new AdmissionProblem('Batas undangan tercapai. Coba lagi dalam 15 menit.', 429);
            }
            inviteStaff($db, $config, (int) $user['id'], $values);
            flash($config['mail_transport'] === 'file' ? 'Undangan dibuat dalam email lokal privat pada folder mail di APP_STORAGE; belum dikirim ke internet.'
                : 'Undangan dikirim. Tautan berlaku 24 jam.');
        }
        redirect('/admin/accounts');
    }
    $schools = $db->query('SELECT id,name,npsn FROM master_schools ORDER BY name COLLATE NOCASE,id')->fetchAll();
    $rows = $db->query('SELECT u.name,u.email,a.role,a.created_at FROM admin_accounts a JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC')->fetchAll();
    $from = ' FROM staff_accounts s JOIN users u ON u.id=s.user_id LEFT JOIN account_security sec ON sec.user_id=u.id
        WHERE instr(lower(u.name || \' \' || u.email),lower(?)) > 0';
    $statement = $db->prepare('SELECT COUNT(*)' . $from);
    $statement->execute([$query]);
    $total = (int) $statement->fetchColumn();
    $statement = $db->prepare('SELECT u.id,u.name,u.email,s.*,sec.email_verified_at,sec.mfa_secret IS NOT NULL AS mfa_enabled' . $from
        . ' ORDER BY u.name COLLATE NOCASE,u.id LIMIT 10 OFFSET ' . (($page - 1) * 10));
    $statement->execute([$query]);
    $staffRows = $statement->fetchAll();
    foreach ($staffRows as &$row) {
        $statement = $db->prepare('SELECT m.name FROM staff_schools s JOIN master_schools m ON m.id=s.school_id WHERE s.user_id=? ORDER BY m.name');
        $statement->execute([$row['id']]);
        $row['schools'] = $statement->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($row);
    $invitations = $db->query('SELECT id,name,email,role,expires_at FROM staff_invitations
        WHERE used_at IS NULL AND cancelled_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 50')->fetchAll();
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors['form'] = $exception->getMessage();
    if (in_array($exception->status, [403, 404, 405], true)) {
        $screen = 'not-found';
    } else {
        $schools = $db->query('SELECT id,name,npsn FROM master_schools ORDER BY name COLLATE NOCASE,id')->fetchAll();
    }
}
$page = $page ?: 1;
$notice = takeFlash();
require __DIR__ . '/views/admin.php';
