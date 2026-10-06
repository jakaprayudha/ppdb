<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    require dirname(__DIR__) . '/app/admissions.php';
    if ($config['environment'] !== 'development') {
        throw new RuntimeException('Akun admin uji hanya dapat dibuat pada development.');
    }
    if (($argv[1] ?? '') !== 'seed') {
        throw new InvalidArgumentException('Gunakan: php bin/admin.php seed');
    }
    $email = 'admin.pusat@example.test';
    $password = 'Admin-Uji-' . bin2hex(random_bytes(10));
    $created = admissionTransaction($db, function () use ($db, $email, $password): bool {
        $select = $db->prepare('SELECT u.id, a.role FROM users u LEFT JOIN admin_accounts a ON a.user_id = u.id WHERE u.email = ?');
        $select->execute([$email]);
        $existing = $select->fetch();
        if ($existing) {
            if ($existing['role'] !== 'central_admin') {
                throw new RuntimeException('Email akun uji sudah digunakan wali. Tidak dipromosikan otomatis.');
            }
            return false;
        }
        $db->prepare('INSERT INTO users(name, email, password_hash, privacy_acknowledged_at, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['Admin Pusat PPDB', $email, password_hash($password, PASSWORD_DEFAULT), time(), time()]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO admin_accounts(user_id, role, created_at) VALUES (?, ?, ?)')
            ->execute([$id, 'central_admin', time()]);
        audit($db, 'admin.account_created', $id);
        return true;
    });
    fwrite(STDOUT, $created
        ? "Akun admin pusat uji dibuat.\nEmail: $email\nPassword: $password\nMasuk melalui /login.\n"
        : "Akun admin pusat uji sudah ada. Password tidak diubah. Gunakan pemulihan password bila diperlukan.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
