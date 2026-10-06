<?php
declare(strict_types=1);

function accountSecurity(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT * FROM account_security WHERE user_id=?');
    $statement->execute([$id]);
    return $statement->fetch() ?: ['email_verified_at' => null, 'mfa_secret' => null, 'last_counter' => -1];
}

function staffSecurityReady(PDO $db, array $user): bool
{
    $security = accountSecurity($db, (int) $user['id']);
    return $security['email_verified_at'] !== null && $security['mfa_secret'] !== null
        && ($_SESSION['mfa_verified'] ?? false) === true;
}

function staffPortalReady(PDO $db, array $config, array $user): bool
{
    return accountSecurity($db, (int) $user['id'])['email_verified_at'] !== null && accountMfaReady($db, $user);
}

function accountMfaReady(PDO $db, array $user): bool
{
    return accountSecurity($db, (int) $user['id'])['mfa_secret'] === null
        || ($_SESSION['mfa_verified'] ?? false) === true;
}

function requireSecuritySession(PDO $db, array $user): void
{
    $statement = $db->prepare('SELECT u.auth_version,COALESCE(s.enabled,1) AS enabled FROM users u
        LEFT JOIN staff_accounts s ON s.user_id=u.id WHERE u.id=?');
    $statement->execute([$user['id']]);
    $account = $statement->fetch();
    if (!$account || !(int) $account['enabled'] || (int) $account['auth_version'] !== (int) $user['auth_version']) {
        throw new AdmissionProblem('Akses atau sesi telah berubah. Masuk kembali sebelum melanjutkan.', 409);
    }
}

function sendEmailVerification(PDO $db, array $config, array $user): void
{
    $token = bin2hex(random_bytes(32));
    admissionTransaction($db, function () use ($db, $config, $user, $token): void {
        $db->prepare('UPDATE email_verifications SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([time(), $user['id']]);
        $db->prepare('INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)')
            ->execute([hash('sha256', $token), $user['id'], time() + 1800]);
        sendAccountEmail($config, $user['email'], 'Verifikasi email PPDB',
            "Verifikasi kepemilikan email akun PPDB melalui tautan berikut dalam 30 menit:\n"
            . $config['base_url'] . '/account/verify-email?token=' . $token
            . "\n\nJika bukan Anda yang meminta, abaikan email ini.\n");
        audit($db, 'auth.email_verification_requested', (int) $user['id']);
    });
}

function verifyEmailToken(PDO $db, string $token): void
{
    admissionTransaction($db, function () use ($db, $token): void {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new AdmissionProblem('Tautan verifikasi tidak valid.', 410);
        }
        $statement = $db->prepare('SELECT user_id FROM email_verifications WHERE token_hash=? AND used_at IS NULL AND expires_at>?');
        $statement->execute([hash('sha256', $token), time()]);
        $id = $statement->fetchColumn();
        if (!$id) {
            throw new AdmissionProblem('Tautan verifikasi kedaluwarsa atau sudah digunakan. Minta tautan baru.', 410);
        }
        $db->prepare('INSERT INTO account_security(user_id,email_verified_at) VALUES(?,?)
            ON CONFLICT(user_id) DO UPDATE SET email_verified_at=excluded.email_verified_at')->execute([$id, time()]);
        $db->prepare('UPDATE email_verifications SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([time(), $id]);
        audit($db, 'auth.email_verified', (int) $id);
    });
}

function encodeTotpSecret(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($bits, 5) as $chunk) {
        $result .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $result;
}

function totpCode(string $secret, int $counter): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($secret) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) {
            throw new RuntimeException('Secret MFA tidak valid.');
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $bytes .= chr(bindec($chunk));
        }
    }
    $hash = hash_hmac('sha1', pack('N2', 0, $counter), $bytes, true);
    $offset = ord($hash[19]) & 15;
    $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
}

function totpCounter(string $secret, string $code, int $last = -1): ?int
{
    if (!preg_match('/\A[0-9]{6}\z/', $code)) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([$now, $now - 1, $now + 1] as $counter) {
        if ($counter > $last && hash_equals(totpCode($secret, $counter), $code)) {
            return $counter;
        }
    }
    return null;
}

function mfaStorageKey(string $storage): string
{
    $path = $storage . '/mfa.key';
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            try {
                if (fwrite($handle, random_bytes(32)) !== 32 || !fflush($handle)) {
                    throw new RuntimeException('Gagal menyimpan kunci enkripsi MFA.');
                }
            } finally {
                fclose($handle);
            }
        } elseif (!is_file($path)) {
            throw new RuntimeException('Kunci enkripsi MFA tidak dapat dibuat.');
        }
    }
    $key = file_get_contents($path);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Kunci enkripsi MFA tidak tersedia/rusak; pulihkan backup kunci, jangan menggantinya.');
    }
    return $key;
}

function encryptMfaSecret(string $storage, int $id, string $secret): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Ekstensi OpenSSL diperlukan untuk MFA.');
    }
    $nonce = random_bytes(12);
    $encrypted = openssl_encrypt($secret, 'aes-256-gcm', mfaStorageKey($storage), OPENSSL_RAW_DATA, $nonce, $tag, 'ppdb-mfa:' . $id);
    if ($encrypted === false) {
        throw new RuntimeException('Gagal mengenkripsi secret MFA.');
    }
    return base64_encode($nonce . $tag . $encrypted);
}

function decryptMfaSecret(string $storage, int $id, string $value): string
{
    $bytes = base64_decode($value, true);
    if ($bytes === false || strlen($bytes) < 29) {
        throw new RuntimeException('Data MFA rusak.');
    }
    if (!is_file($storage . '/mfa.key')) {
        throw new RuntimeException('Kunci MFA hilang; pulihkan dari backup privat.');
    }
    $secret = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', mfaStorageKey($storage), OPENSSL_RAW_DATA,
        substr($bytes, 0, 12), substr($bytes, 12, 16), 'ppdb-mfa:' . $id);
    if ($secret === false) {
        throw new RuntimeException('Integritas secret MFA gagal.');
    }
    return $secret;
}

function enrollMfa(PDO $db, array $config, array $user, string $secret, string $code): array
{
    $counter = totpCounter($secret, $code);
    if ($counter === null) {
        throw new AdmissionProblem('Kode autentikator tidak sesuai. Periksa jam perangkat dan coba lagi.', 422);
    }
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = bin2hex(random_bytes(8));
    }
    admissionTransaction($db, function () use ($db, $config, $user, $secret, $counter, $codes): void {
        requireSecuritySession($db, $user);
        $security = accountSecurity($db, (int) $user['id']);
        if ($security['email_verified_at'] === null || $security['mfa_secret'] !== null) {
            throw new AdmissionProblem('Email belum terverifikasi atau MFA sudah diatur. Muat ulang halaman.', 409);
        }
        $db->prepare('UPDATE account_security SET mfa_secret=?,last_counter=? WHERE user_id=?')
            ->execute([encryptMfaSecret($config['storage'], (int) $user['id'], $secret), $counter, $user['id']]);
        $insert = $db->prepare('INSERT INTO mfa_recovery_codes(user_id,code_hash) VALUES(?,?)');
        foreach ($codes as $recovery) {
            $insert->execute([$user['id'], hash('sha256', $recovery)]);
        }
        $db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$user['id']]);
        audit($db, 'auth.mfa_enrolled', (int) $user['id']);
    });
    $_SESSION['auth_version']++;
    $_SESSION['mfa_verified'] = true;
    session_regenerate_id(true);
    return $codes;
}

function verifyMfa(PDO $db, array $config, array $user, string $code): void
{
    admissionTransaction($db, function () use ($db, $config, $user, $code): void {
        requireSecuritySession($db, $user);
        $security = accountSecurity($db, (int) $user['id']);
        if (!$security['mfa_secret'] || !$security['email_verified_at']) {
            throw new AdmissionProblem('Lengkapi verifikasi email dan pengaturan MFA dahulu.', 409);
        }
        $counter = totpCounter(decryptMfaSecret($config['storage'], (int) $user['id'], $security['mfa_secret']), $code, (int) $security['last_counter']);
        if ($counter !== null) {
            $db->prepare('UPDATE account_security SET last_counter=? WHERE user_id=?')->execute([$counter, $user['id']]);
            audit($db, 'auth.mfa_verified', (int) $user['id']);
        } else {
            $statement = $db->prepare('UPDATE mfa_recovery_codes SET used_at=? WHERE user_id=? AND code_hash=? AND used_at IS NULL');
            $statement->execute([time(), $user['id'], hash('sha256', trim($code))]);
            if ($statement->rowCount() !== 1) {
                throw new AdmissionProblem('Kode salah atau sudah digunakan. Gunakan kode baru atau kode pemulihan sekali pakai.', 422);
            }
            audit($db, 'auth.mfa_recovery_used', (int) $user['id']);
        }
    });
    session_regenerate_id(true);
    $_SESSION['mfa_verified'] = true;
}
