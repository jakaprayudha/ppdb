<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    require dirname(__DIR__) . '/app/admissions.php';
    cleanupCancelledDocuments($db, $config['storage']);
    fwrite(STDOUT, "Antrean penghapusan berkas draf yang dibatalkan selesai.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
