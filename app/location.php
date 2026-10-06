<?php
declare(strict_types=1);

function locationCoordinates(array $input): array
{
    $coordinates = [];
    foreach (['latitude' => 90, 'longitude' => 180] as $key => $maximum) {
        $value = $input[$key] ?? null;
        if (!is_scalar($value) || is_bool($value) || !is_numeric($value)
            || !is_finite((float) $value) || abs((float) $value) > $maximum) {
            throw new AdmissionProblem('Koordinat lokasi tidak valid. Ambil lokasi kembali.', 422);
        }
        $coordinates[$key] = (float) $value;
    }
    return $coordinates;
}

function locationAddress(array $result): array
{
    if (!isset($result['country_code']) || !is_string($result['country_code'])) {
        throw new AdmissionProblem('Respons layanan lokasi tidak valid.', 502);
    }
    if (strtolower($result['country_code']) !== 'id') {
        throw new AdmissionProblem('Lokasi berada di luar Indonesia. Periksa lokasi perangkat Anda.', 422);
    }
    $address = [];
    foreach (['province', 'city', 'district', 'village', 'postal_code'] as $field) {
        $value = $result[$field] ?? '';
        if (!is_string($value) || mb_strlen($value) > ($field === 'postal_code' ? 5 : 100)
            || preg_match('/[\x00-\x1f\x7f]/', $value)
            || ($field === 'postal_code' && $value !== '' && !preg_match('/\A[0-9]{5}\z/', $value))) {
            throw new AdmissionProblem('Respons alamat layanan lokasi tidak valid.', 502);
        }
        $address[$field] = trim($value);
    }
    if (!array_filter($address)) {
        throw new AdmissionProblem('Alamat tidak ditemukan untuk lokasi ini. Isi alamat secara manual.', 422);
    }
    return $address;
}

function lookupLocation(string $url, array $coordinates): array
{
    if ($url === '') {
        throw new AdmissionProblem('Layanan lokasi internal belum dikonfigurasi. Isi alamat secara manual sementara.', 503);
    }
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host'], $parts['scheme'])
        || !in_array($parts['scheme'], ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || isset($parts['query'])) {
        throw new AdmissionProblem('Konfigurasi layanan lokasi tidak valid. Hubungi pengelola.', 503);
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode($coordinates, JSON_THROW_ON_ERROR),
        'timeout' => 8,
        'follow_location' => 0,
        'ignore_errors' => true,
    ]]);
    // Do not let stream warnings expose the request or internal service URL.
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        error_log('[PPDB] Internal geocoding connection failed.');
        throw new AdmissionProblem('Layanan lokasi tidak dapat dihubungi. Coba lagi atau isi alamat secara manual.', 502);
    }
    try {
        $metadata = stream_get_meta_data($stream);
        $headers = $metadata['wrapper_data'] ?? [];
        if (!isset($headers[0]) || !preg_match('~\AHTTP/\S+ 200(?: |$)~', $headers[0])) {
            throw new AdmissionProblem('Layanan lokasi sedang tidak tersedia. Coba lagi atau isi alamat secara manual.', 502);
        }
        $body = stream_get_contents($stream, 32769);
        $metadata = stream_get_meta_data($stream);
        if ($body === false || strlen($body) > 32768 || $metadata['timed_out'] || !feof($stream)) {
            throw new AdmissionProblem('Respons layanan lokasi tidak lengkap atau terlalu besar.', 502);
        }
        try {
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AdmissionProblem('Respons layanan lokasi bukan alamat yang valid.', 502);
        }
        if (!is_array($result)) {
            throw new AdmissionProblem('Respons layanan lokasi tidak valid.', 502);
        }
        return locationAddress($result);
    } finally {
        fclose($stream);
    }
}

function handleLocationRequest(PDO $db, array $config, int $userId): void
{
    header('Content-Type: application/json; charset=UTF-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new AdmissionProblem('Gunakan tombol Ambil lokasi pada formulir profil.', 405);
        }
        if (!validCsrf()) {
            throw new AdmissionProblem('Sesi formulir tidak valid. Muat ulang halaman.', 419);
        }
        if ($config['environment'] === 'production') {
            throw new AdmissionProblem('Modul registrasi belum dibuka untuk data nyata.', 403);
        }
        if (input('location_consent') !== '1') {
            throw new AdmissionProblem('Persetujuan penggunaan lokasi diperlukan.', 422);
        }
        $coordinates = locationCoordinates($_POST);
        if (!consumeRateLimit($db, 'location', (string) $userId, 15)) {
            header('Retry-After: 900');
            throw new AdmissionProblem('Terlalu banyak permintaan lokasi. Coba lagi dalam 15 menit.', 429);
        }
        $address = lookupLocation($config['geocoding_url'], $coordinates);
        echo json_encode(['address' => $address], JSON_THROW_ON_ERROR);
    } catch (AdmissionProblem $exception) {
        http_response_code($exception->status);
        echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
    }
}
