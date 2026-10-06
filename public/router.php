<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = ['/assets/app.css', '/assets/app.js'];
if (is_string($path) && in_array($path, $assets, true)) {
    return false;
}
require __DIR__ . '/index.php';
