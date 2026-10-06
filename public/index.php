<?php
declare(strict_types=1);

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $user = currentUser($db);
    if (in_array($path, ['/staff/accept', '/account/security', '/account/verify-email'], true)) {
        require dirname(__DIR__) . '/app/account_controller.php';
        exit;
    }
    if ($user
        && ($path === '/dashboard' || preg_match('~\A/(admin|participants|admissions|applications|documents)(/|$)~', $path))
        && ((isStaff($user) && $config['environment'] === 'development' && !staffPortalReady($db, $config, $user))
            || !accountMfaReady($db, $user))) {
        redirect('/account/security');
    }
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        if (!$user) {
            redirect('/login');
        }
        require dirname(__DIR__) . '/app/admin_controller.php';
        exit;
    }
    if ($path === '/dashboard' || preg_match('~\A/(participants|admissions|applications|documents)(/|$)~', $path)) {
        if (!$user) {
            redirect('/login');
        }
        if (isStaff($user) && !str_starts_with($path, '/documents/')) {
            redirect('/admin');
        }
        require dirname(__DIR__) . '/app/admission_controller.php';
        exit;
    }
    $routes = ['/', '/login', '/register', '/forgot-password', '/reset-password', '/dashboard', '/logout', '/privacy'];
    if (!in_array($path, $routes, true)) {
        http_response_code(404);
        $page = 'not-found';
    } else {
        $page = $path === '/' ? 'login' : substr($path, 1);
    }

    if ($page === 'dashboard' && !$user) {
        redirect('/login');
    }
    if ($user && in_array($page, ['login', 'register', 'forgot-password'], true)) {
        redirect('/dashboard');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    $errors = [];
    $values = ['name' => '', 'email' => ''];
    $tokenValue = $_GET['token'] ?? '';
    $resetToken = is_string($tokenValue) ? $tokenValue : '';
    $reset = $page === 'reset-password' ? findReset($db, $resetToken) : null;
    $resetInvalid = $page === 'reset-password' && !$reset;

    if (!in_array($method, ['GET', 'POST'], true)
        || ($method === 'POST' && !in_array($page, ['login', 'register', 'forgot-password', 'reset-password', 'logout'], true))) {
        header('Allow: ' . (in_array($page, ['login', 'register', 'forgot-password', 'reset-password', 'logout'], true) ? 'GET, POST' : 'GET'));
        http_response_code(405);
        $errors['form'] = 'Metode permintaan tidak didukung.';
    } elseif ($page === 'logout' && $method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        $errors['form'] = 'Gunakan tombol keluar untuk mengakhiri sesi.';
    } elseif ($method === 'POST') {
        if (!validCsrf()) {
            http_response_code(419);
            $errors['form'] = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
        } elseif ($page === 'logout') {
            if ($user) {
                audit($db, 'auth.logout', (int) $user['id']);
            }
            $_SESSION = [];
            session_regenerate_id(true);
            flash('Anda berhasil keluar dengan aman.');
            redirect('/login');
        } else {
            $values = ['name' => trim(input('name')), 'email' => normalizedEmail(input('email'))];
            $identity = $page === 'reset-password' ? hash('sha256', $resetToken) : $values['email'];
            if (!consumeRateLimit($db, $page, $identity, $page === 'forgot-password' ? 3 : 8)) {
                http_response_code(429);
                header('Retry-After: 900');
                $errors['form'] = 'Terlalu banyak percobaan. Silakan coba lagi dalam 15 menit.';
            } elseif ($page === 'register') {
                if (mb_strlen($values['name']) < 2 || mb_strlen($values['name']) > 100 || preg_match('/[\x00-\x1f\x7f]/', $values['name'])) {
                    $errors['name'] = 'Isi nama lengkap antara 2 sampai 100 karakter.';
                }
                if (!validEmail($values['email'])) {
                    $errors['email'] = 'Isi alamat email yang valid.';
                }
                $passwordMessage = passwordError(input('password'), input('password_confirmation'));
                if ($passwordMessage) {
                    $errors['password'] = $passwordMessage;
                }
                if (input('privacy') !== '1') {
                    $errors['privacy'] = 'Anda perlu membaca pemberitahuan privasi sebelum membuat akun.';
                }
                if (!$errors) {
                    $db->exec('BEGIN IMMEDIATE');
                    try {
                        $existing = $db->prepare('SELECT id FROM users WHERE email = ?');
                        $existing->execute([$values['email']]);
                        if ($existing->fetch()) {
                            $errors['email'] = 'Email ini sudah terdaftar. Silakan masuk atau pulihkan password.';
                            $db->exec('ROLLBACK');
                        } else {
                            $statement = $db->prepare('INSERT INTO users (name, email, password_hash, privacy_acknowledged_at, created_at) VALUES (?, ?, ?, ?, ?)');
                            $statement->execute([$values['name'], $values['email'], password_hash(input('password'), PASSWORD_DEFAULT), time(), time()]);
                            audit($db, 'auth.register', (int) $db->lastInsertId());
                            $db->exec('COMMIT');
                            flash('Akun berhasil dibuat. Silakan masuk dengan email dan password Anda.');
                            redirect('/login');
                        }
                    } catch (Throwable $exception) {
                        $db->exec('ROLLBACK');
                        throw $exception;
                    }
                }
            } elseif ($page === 'login') {
                if (!validEmail($values['email'])) {
                    $errors['email'] = 'Isi alamat email yang valid.';
                }
                if (input('password') === '' || strlen(input('password')) > 72 || str_contains(input('password'), "\0")) {
                    $errors['password'] = 'Isi password yang valid.';
                }
                if (!$errors) {
                    $statement = $db->prepare('SELECT u.*, COALESCE(s.enabled,1) AS enabled FROM users u
                        LEFT JOIN staff_accounts s ON s.user_id=u.id WHERE u.email = ?');
                    $statement->execute([$values['email']]);
                    $account = $statement->fetch();
                    $dummyHash = password_hash('dummy-password-not-an-account', PASSWORD_DEFAULT);
                    $verified = password_verify(input('password'), $account ? $account['password_hash'] : $dummyHash);
                    if (!$account || !$verified || !(int) $account['enabled']) {
                        audit($db, 'auth.login_failed');
                        $errors['form'] = 'Email atau password tidak sesuai.';
                    } else {
                        if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
                            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([
                                password_hash(input('password'), PASSWORD_DEFAULT), $account['id'],
                            ]);
                        }
                        audit($db, 'auth.login', (int) $account['id']);
                        signIn($account);
                        redirect('/dashboard');
                    }
                }
            } elseif ($page === 'forgot-password') {
                if (!validEmail($values['email'])) {
                    $errors['email'] = 'Isi alamat email yang valid.';
                }
                if (!$errors) {
                    $statement = $db->prepare('SELECT id FROM users WHERE email = ?');
                    $statement->execute([$values['email']]);
                    $account = $statement->fetch();
                    if ($account) {
                        $token = bin2hex(random_bytes(32));
                        $statement = $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)');
                        $statement->execute([$account['id'], hash('sha256', $token), time() + 1800, time()]);
                        $resetId = (int) $db->lastInsertId();
                        try {
                            sendResetEmail($config, $values['email'], $token);
                        } catch (Throwable $exception) {
                            $db->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$resetId]);
                            throw $exception;
                        }
                        audit($db, 'auth.reset_requested', (int) $account['id']);
                    }
                    flash('Jika email terdaftar, tautan pemulihan akan dikirim. Periksa kotak masuk dan folder spam.');
                    redirect('/forgot-password');
                }
            } elseif ($page === 'reset-password') {
                if (!$reset) {
                    $errors['form'] = 'Tautan tidak valid, sudah digunakan, atau telah kedaluwarsa. Silakan minta tautan baru.';
                } else {
                    $passwordMessage = passwordError(input('password'), input('password_confirmation'));
                    if ($passwordMessage) {
                        $errors['password'] = $passwordMessage;
                    }
                    if (!$errors) {
                        $hash = password_hash(input('password'), PASSWORD_DEFAULT);
                        $db->exec('BEGIN IMMEDIATE');
                        try {
                            $validReset = findReset($db, $resetToken);
                            if (!$validReset) {
                                $db->exec('ROLLBACK');
                                $resetInvalid = true;
                                $errors['form'] = 'Tautan tidak berlaku lagi. Silakan minta tautan baru.';
                            } else {
                                $db->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                                    ->execute([$hash, $validReset['user_id']]);
                                $db->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                                    ->execute([time(), $validReset['user_id']]);
                                audit($db, 'auth.password_reset', (int) $validReset['user_id']);
                                $db->exec('COMMIT');
                                $_SESSION = [];
                                session_regenerate_id(true);
                                flash('Password berhasil diubah. Silakan masuk kembali. Sesi lama telah diakhiri.');
                                redirect('/login');
                            }
                        } catch (Throwable $exception) {
                            $db->exec('ROLLBACK');
                            throw $exception;
                        }
                    }
                }
            }
            if ($errors && http_response_code() === 200) {
                http_response_code(422);
            }
        }
    }

    $notice = takeFlash();
    require dirname(__DIR__) . '/app/views/page.php';
} catch (Throwable $exception) {
    error_log('[SPMB] ' . get_class($exception) . ': ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Layanan belum tersedia — SPMB</title><body><main><h1>Layanan belum tersedia</h1>'
        . '<p>Permintaan belum dapat diselesaikan. Silakan coba lagi nanti atau hubungi pengelola.</p>'
        . '<a href="/login">Kembali ke halaman masuk</a></main></body></html>';
}
