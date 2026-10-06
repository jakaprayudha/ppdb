<?php
declare(strict_types=1);

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assetUrl(string $name): string
{
    if (!in_array($name, ['app.css', 'app.js', 'master.js'], true)) {
        throw new InvalidArgumentException('Aset tidak dikenal.');
    }
    $hash = hash_file('sha256', dirname(__DIR__) . '/public/assets/' . $name);
    if ($hash === false) {
        throw new RuntimeException('Aset aplikasi tidak tersedia.');
    }
    return '/assets/' . $name . '?v=' . substr($hash, 0, 16);
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function validCsrf(): bool
{
    $token = $_POST['csrf'] ?? null;
    return is_string($token) && hash_equals(csrfToken(), $token);
}

function input(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function normalizedEmail(string $email): string
{
    return strtolower(trim($email));
}

function validEmail(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function passwordError(string $password, string $confirmation): ?string
{
    if (mb_strlen($password) < 12 || strlen($password) > 72) {
        return 'Gunakan minimal 12 karakter dan maksimal 72 byte untuk password.';
    }
    if (str_contains($password, "\0")) {
        return 'Password mengandung karakter yang tidak didukung.';
    }
    if ($password !== $confirmation) {
        return 'Konfirmasi password belum sama.';
    }
    return null;
}

function audit(PDO $db, string $action, ?int $userId = null): void
{
    $statement = $db->prepare('INSERT INTO audit_events (user_id, action, created_at) VALUES (?, ?, ?)');
    $statement->execute([$userId, $action, time()]);
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function takeFlash(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function currentUser(PDO $db): ?array
{
    if (!isset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['last_active'], $_SESSION['signed_in_at'])) {
        return null;
    }
    if (time() - $_SESSION['last_active'] > 1800 || time() - $_SESSION['signed_in_at'] > 43200) {
        unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['last_active'], $_SESSION['signed_in_at']);
        flash('Sesi berakhir. Silakan masuk kembali.');
        return null;
    }
    $statement = $db->prepare('SELECT u.id, u.name, u.email, COALESCE(a.role, u.role) AS role, u.auth_version
        FROM users u LEFT JOIN admin_accounts a ON a.user_id = u.id WHERE u.id = ?');
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || (int) $user['auth_version'] !== $_SESSION['auth_version']) {
        unset($_SESSION['user_id'], $_SESSION['auth_version'], $_SESSION['last_active'], $_SESSION['signed_in_at']);
        flash('Sesi tidak berlaku lagi. Silakan masuk kembali.');
        return null;
    }
    $_SESSION['last_active'] = time();
    return $user;
}

function signIn(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'auth_version' => (int) $user['auth_version'],
        'last_active' => time(),
        'signed_in_at' => time(),
        'csrf' => bin2hex(random_bytes(32)),
    ];
}

function consumeRateLimit(PDO $db, string $action, string $email, int $maximum): bool
{
    // REMOTE_ADDR is trusted; forwarded headers are not accepted from arbitrary clients.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $buckets = [
        [hash('sha256', $action . ':ip:' . $ip), $maximum * 5],
        [hash('sha256', $action . ':identity:' . $email), $maximum],
    ];
    $now = time();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM rate_limits WHERE expires_at <= ?')->execute([$now]);
        $select = $db->prepare('SELECT attempts FROM rate_limits WHERE bucket = ?');
        foreach ($buckets as [$bucket, $limit]) {
            $select->execute([$bucket]);
            if ((int) $select->fetchColumn() >= $limit) {
                $db->exec('COMMIT');
                return false;
            }
        }
        $increment = $db->prepare('INSERT INTO rate_limits (bucket, attempts, expires_at) VALUES (?, 1, ?)
            ON CONFLICT(bucket) DO UPDATE SET attempts = attempts + 1');
        foreach ($buckets as [$bucket]) {
            $increment->execute([$bucket, $now + 900]);
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function sendResetEmail(array $config, string $email, string $token): void
{
    $link = $config['base_url'] . '/reset-password?token=' . rawurlencode($token);
    $subject = 'Atur ulang password SPMB';
    $body = "Kami menerima permintaan untuk mengganti password akun SPMB Anda.\n\n"
        . "Buka tautan berikut dalam 30 menit:\n$link\n\n"
        . "Tautan hanya dapat digunakan sekali. Jika bukan Anda yang meminta, abaikan email ini.\n";
    if ($config['mail_transport'] === 'file') {
        $path = $config['storage'] . '/mail/' . time() . '-' . bin2hex(random_bytes(8)) . '.eml';
        $contents = "To: $email\nSubject: $subject\nContent-Type: text/plain; charset=UTF-8\n\n$body";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menyimpan email pemulihan lokal.');
        }
        return;
    }
    if (!validEmail($config['mail_from'])) {
        throw new RuntimeException('MAIL_FROM harus berupa alamat email valid.');
    }
    if (!mail($email, $subject, $body, [
        'From' => $config['mail_from'],
        'Content-Type' => 'text/plain; charset=UTF-8',
    ])) {
        throw new RuntimeException('Layanan email menolak pengiriman.');
    }
}

function findReset(PDO $db, string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }
    $statement = $db->prepare('SELECT id, user_id FROM password_resets
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?');
    $statement->execute([hash('sha256', $token), time()]);
    return $statement->fetch() ?: null;
}
