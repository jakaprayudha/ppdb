<?php
declare(strict_types=1);
require_once __DIR__ . '/admissions.php';

$errors = [];
$notice = takeFlash();
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$screen = match ($path) {
    '/staff/accept' => 'invite',
    '/account/verify-email' => 'verify-email',
    default => 'security',
};
$invite = $screen === 'invite' ? findStaffInvitation($db, $token) : null;
$secret = null;
$recoveryCodes = $_SESSION['new_recovery_codes'] ?? [];
unset($_SESSION['new_recovery_codes']);
try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new AdmissionProblem('Metode permintaan tidak didukung.', 405);
    }
    if ($screen === 'security' && !$user) {
        redirect('/login');
    }
    if ($screen === 'invite' && $config['environment'] !== 'development') {
        throw new AdmissionProblem('Aktivasi staf belum dibuka pada produksi.', 403);
    }
    if ($screen === 'invite' && !$invite) {
        throw new AdmissionProblem('Undangan tidak valid, digunakan, dibatalkan atau kedaluwarsa.', 410);
    }
    if ($screen === 'invite' && $user) {
        throw new AdmissionProblem('Keluar dari akun saat ini sebelum menerima undangan staf.', 409);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validCsrf()) {
            throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman.', 419);
        }
        if (!consumeRateLimit($db, 'account-' . $screen, $user ? (string) $user['id'] : hash('sha256', $token), 8)) {
            header('Retry-After: 900');
            throw new AdmissionProblem('Terlalu banyak percobaan. Coba lagi dalam 15 menit.', 429);
        }
        if ($screen === 'invite') {
            acceptStaffInvitation($db, $token, input('password'), input('password_confirmation'));
            flash('Akun staf diaktifkan dan email terverifikasi. 2FA dapat diaktifkan melalui pengaturan profil akun.');
            redirect('/login');
        } elseif ($screen === 'verify-email') {
            verifyEmailToken($db, $token);
            flash('Email berhasil diverifikasi.');
            redirect($user ? '/account/security' : '/login');
        } else {
            $action = input('action');
            if ($action === 'send-email') {
                if (!consumeRateLimit($db, 'email-verification', (string) $user['id'], 3)) {
                    throw new AdmissionProblem('Batas kirim tautan tercapai. Coba lagi dalam 15 menit.', 429);
                }
                sendEmailVerification($db, $config, $user);
                flash($config['mail_transport'] === 'file'
                    ? 'Tautan disimpan di email lokal privat. Pengelola dapat membuka folder mail pada APP_STORAGE; email belum dikirim ke internet.'
                    : 'Tautan verifikasi dikirim ke email akun Anda.');
                redirect('/account/security');
            }
            if ($action === 'start-setup') {
                if (!accountSecurity($db, (int) $user['id'])['email_verified_at']) {
                    throw new AdmissionProblem('Verifikasi email sebelum mengaktifkan 2FA.', 403);
                }
                if (accountSecurity($db, (int) $user['id'])['mfa_secret']) {
                    throw new AdmissionProblem('2FA sudah aktif. Gunakan penggantian autentikator.', 409);
                }
                $_SESSION['mfa_setup'] = ['requested' => true, 'secret' => encodeTotpSecret(random_bytes(20)), 'expires_at' => time() + 600, 'user_id' => (int) $user['id']];
                redirect('/account/security');
            }
            if ($action === 'cancel-setup') {
                unset($_SESSION['mfa_setup']);
                flash('Pengaturan 2FA dibatalkan. 2FA belum diaktifkan.');
                redirect('/account/security');
            }
            if (in_array($action, ['rotate', 'disable'], true)) {
                if (!staffSecurityReady($db, $user) || input('confirm_rotate') !== '1') {
                    throw new AdmissionProblem('Verifikasi 2FA sesi dan konfirmasikan perubahan autentikator.', 403);
                }
                $statement = $db->prepare('SELECT password_hash FROM users WHERE id=?');
                $statement->execute([$user['id']]);
                if (!password_verify(input('password'), $statement->fetchColumn())) {
                    throw new AdmissionProblem('Password akun tidak sesuai.', 422);
                }
                admissionTransaction($db, function () use ($db, $user, $action): void {
                    requireSecuritySession($db, $user);
                    $db->prepare('UPDATE account_security SET mfa_secret=NULL,last_counter=-1 WHERE user_id=?')->execute([$user['id']]);
                    $db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id=?')->execute([$user['id']]);
                    $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$user['id']]);
                    audit($db, $action === 'disable' ? 'auth.mfa_disabled' : 'auth.mfa_rotation_started', (int) $user['id']);
                });
                $_SESSION['auth_version']++;
                unset($_SESSION['mfa_verified'], $_SESSION['mfa_setup'], $_SESSION['mfa_testing_skipped']);
                session_regenerate_id(true);
                if ($action === 'rotate') {
                    $_SESSION['mfa_setup'] = ['requested' => true, 'secret' => encodeTotpSecret(random_bytes(20)), 'expires_at' => time() + 600, 'user_id' => (int) $user['id']];
                }
                flash($action === 'disable' ? '2FA dinonaktifkan. Autentikator, kode pemulihan dan sesi lain dicabut.'
                    : 'Autentikator lama dan kode pemulihan dicabut. Selesaikan pengaturan autentikator baru untuk mengaktifkan 2FA kembali.');
                redirect('/account/security');
            }
            if ($action === 'enroll') {
                $pending = $_SESSION['mfa_setup'] ?? null;
                if (!$pending || ($pending['requested'] ?? false) !== true || $pending['expires_at'] < time() || $pending['user_id'] !== (int) $user['id']) {
                    throw new AdmissionProblem('Pengaturan MFA kedaluwarsa. Muat ulang halaman.', 409);
                }
                $password = $db->prepare('SELECT password_hash FROM users WHERE id=?');
                $password->execute([$user['id']]);
                if (!password_verify(input('password'), $password->fetchColumn())) {
                    throw new AdmissionProblem('Password akun tidak sesuai.', 422);
                }
                $codes = enrollMfa($db, $config, $user, $pending['secret'], input('code'));
                unset($_SESSION['mfa_setup']);
                $_SESSION['new_recovery_codes'] = $codes;
                redirect('/account/security');
            } elseif ($action === 'challenge') {
                verifyMfa($db, $config, $user, input('code'));
                redirect('/dashboard');
            }
            throw new AdmissionProblem('Aksi keamanan tidak dikenal.', 422);
        }
    }
} catch (AdmissionProblem $exception) {
    http_response_code($exception->status);
    $errors['form'] = $exception->getMessage();
}
$security = $user ? accountSecurity($db, (int) $user['id']) : null;
if ($screen === 'security' && $user && $security['email_verified_at'] && !$security['mfa_secret']) {
    $pending = $_SESSION['mfa_setup'] ?? null;
    if ($pending && (($pending['requested'] ?? false) !== true || $pending['expires_at'] < time() || $pending['user_id'] !== (int) $user['id'])) {
        unset($_SESSION['mfa_setup']);
        $pending = null;
    }
    $secret = $pending['secret'] ?? null;
}
require __DIR__ . '/views/account.php';
