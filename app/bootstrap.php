<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('PPDB memerlukan PHP 8.2 atau lebih baru. Sesuaikan versi PHP web server untuk situs ini.');
}

umask(0077);

$environment = getenv('APP_ENV') ?: 'development';
$baseUrl = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8000', '/');
$urlParts = parse_url($baseUrl);
if (!in_array($environment, ['development', 'production'], true)
    || !$urlParts || !isset($urlParts['scheme'], $urlParts['host'])
    || !in_array($urlParts['scheme'], ['http', 'https'], true)
    || isset($urlParts['query']) || isset($urlParts['fragment']) || isset($urlParts['user']) || isset($urlParts['pass'])
    || !empty($urlParts['path'])) {
    throw new RuntimeException('APP_ENV atau APP_URL tidak valid. APP_URL harus berupa origin tanpa path.');
}

$mailTransport = getenv('MAIL_TRANSPORT') ?: ($environment === 'production' ? 'mail' : 'file');
if (!in_array($mailTransport, ['file', 'mail'], true)
    || ($environment === 'production' && ($urlParts['scheme'] !== 'https' || $mailTransport !== 'mail'))) {
    throw new RuntimeException('Produksi memerlukan APP_URL HTTPS dan MAIL_TRANSPORT=mail.');
}

$storage = getenv('APP_STORAGE') ?: dirname(__DIR__) . '/storage';
if (!str_starts_with($storage, '/')) {
    throw new RuntimeException('APP_STORAGE harus berupa path absolut di luar document root.');
}
if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
    throw new RuntimeException('Tidak dapat membuat direktori penyimpanan.');
}
$storage = realpath($storage);
$publicRoot = realpath(dirname(__DIR__) . '/public');
if ($storage === false || $storage === $publicRoot || str_starts_with($storage, $publicRoot . '/')) {
    throw new RuntimeException('APP_STORAGE tidak boleh berada di folder publik.');
}
foreach ([$storage . '/sessions', $storage . '/mail'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Tidak dapat membuat direktori penyimpanan.');
    }
}

$config = [
    'environment' => $environment,
    'base_url' => $baseUrl,
    'storage' => $storage,
    'mail_transport' => $mailTransport,
    'mail_from' => getenv('MAIL_FROM') ?: 'noreply@example.test',
    'geocoding_url' => getenv('GEOCODING_URL') ?: '',
];

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_save_path($storage . '/sessions');
session_name('spmb_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $urlParts['scheme'] === 'https',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if ($environment === 'production') {
    header('Strict-Transport-Security: max-age=31536000');
}

require __DIR__ . '/database.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/staff.php';
require __DIR__ . '/account_security.php';

$db = database($storage);
