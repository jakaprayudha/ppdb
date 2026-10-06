<?php
declare(strict_types=1);
$title = match ($screen) {
    'invite' => 'Aktivasi akun staf',
    'verify-email' => 'Verifikasi email',
    default => 'Pengaturan profil akun',
};
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= escape($title) ?> — PPDB</title>
<link rel="stylesheet" href="<?= escape(assetUrl('app.css')) ?>"></head>
<body class="dashboard-page">
<a class="skip-link" href="#main">Lewati ke konten</a>
<header class="dashboard-header"><a class="brand" href="/dashboard">PPDB</a>
<?php if ($user): ?><div class="header-actions"><span><?= escape($user['name']) ?></span><form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><button class="button button-outline">Keluar</button></form></div><?php endif; ?></header>
<main id="main" class="dashboard-main admission-main account-settings"><h1><?= escape($title) ?></h1>
<?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error-summary" role="alert"><?= escape($errors['form']) ?></div><?php endif; ?>
<?php if ($screen === 'invite' && $invite && !$user && $config['environment'] === 'development'): ?>
<form method="post" class="form-card"><h2><?= escape($invite['name']) ?></h2><p><?= escape($invite['email'] . ' · ' . staffRoleLabel($invite['role'])) ?></p>
<p>Tautan berlaku 24 jam dan sekali pakai. Buat password sendiri; pengelola tidak dapat melihatnya.</p>
<input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>">
<div class="field"><label for="password">Password baru (minimal 12 karakter)</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
<div class="field"><label for="password_confirmation">Konfirmasi password</label><input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required></div>
<label class="checkbox-field"><input type="checkbox" name="privacy" value="1" required><span>Saya telah membaca <a href="/privacy" target="_blank" rel="noopener">pemberitahuan privasi</a>.</span></label>
<button class="button button-primary compact-button">Aktifkan akun</button></form>
<?php elseif ($screen === 'verify-email'): ?>
<form method="post" class="form-card"><p>Konfirmasikan tautan ini untuk memverifikasi kepemilikan email. Membuka halaman saja belum menggunakan token.</p><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><button class="button button-primary compact-button">Verifikasi email</button></form>
<?php elseif ($screen === 'security' && $user): ?>
<section class="form-card"><h2>Profil akun</h2><p><?= escape($user['name']) ?></p><p><?= escape($user['email']) ?></p></section>
<section class="form-card"><h2>Email akun</h2><p><?= escape($user['email']) ?> · <?= $security['email_verified_at'] ? 'Terverifikasi' : 'Belum terverifikasi' ?></p>
<?php if (!$security['email_verified_at']): ?><form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="send-email"><button class="button button-primary compact-button">Kirim tautan verifikasi</button></form><?php endif; ?></section>
<section class="form-card"><h2>Autentikasi dua faktor (2FA)</h2>
<p>2FA adalah pilihan Anda, bukan syarat setup awal. Jika diaktifkan, kode autentikator atau kode pemulihan akan diminta saat login berikutnya.</p>
<p>Status: <strong><?= $security['mfa_secret'] ? 'Aktif' : 'Nonaktif' ?></strong></p>
<?php if (!$security['email_verified_at']): ?><p>Verifikasi email terlebih dahulu bila ingin mengaktifkan 2FA. Verifikasi email tetap diperlukan untuk akses staf.</p>
<?php elseif ($secret): ?>
<p>Tambahkan akun secara manual di aplikasi autentikator TOTP (6 digit, interval 30 detik, SHA-1). Nama akun: <?= escape('PPDB: ' . $user['email']) ?>. Secret ini rahasia; jangan dibagikan. Pengaturan berlaku 10 menit.</p>
<div class="field"><label for="mfa-secret">Kunci pengaturan autentikator</label><input id="mfa-secret" value="<?= escape($secret) ?>" readonly autocomplete="off"></div>
<form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="enroll">
<div class="field"><label for="password">Konfirmasi password akun</label><input type="password" id="password" name="password" autocomplete="current-password" maxlength="72" required></div>
<div class="field"><label for="code">Kode 6 digit dari autentikator</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>
<button class="button button-primary compact-button">Aktifkan MFA</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="cancel-setup"><button class="button button-outline">Batalkan pengaturan 2FA</button></form>
<?php elseif (!$security['mfa_secret']): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="start-setup"><button class="button button-primary compact-button">Atur 2FA</button></form>
<?php elseif (($_SESSION['mfa_verified'] ?? false) !== true): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="challenge">
<div class="field"><label for="code">Kode autentikator atau kode pemulihan sekali pakai</label><input id="code" name="code" maxlength="16" autocomplete="one-time-code" required></div>
<button class="button button-primary compact-button">Verifikasi dan masuk admin</button></form>
<?php else: ?><p>MFA aktif dan sesi ini sudah terverifikasi.</p>
<details><summary>Ganti autentikator / terbitkan kode pemulihan baru</summary>
<form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="rotate">
<div class="field"><label for="rotate-password">Konfirmasi password</label><input type="password" id="rotate-password" name="password" autocomplete="current-password" maxlength="72" required></div>
<label class="checkbox-field"><input type="checkbox" name="confirm_rotate" value="1" required> Cabut autentikator dan kode pemulihan lama serta seluruh sesi lain. Saya akan mengatur MFA baru.</label>
<button class="button button-outline button-danger">Mulai penggantian MFA</button></form></details>
<details><summary>Nonaktifkan 2FA</summary><form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="disable">
<div class="field"><label for="disable-password">Konfirmasi password akun</label><input type="password" id="disable-password" name="password" autocomplete="current-password" maxlength="72" required></div>
<label class="checkbox-field"><input type="checkbox" name="confirm_rotate" value="1" required> Saya memilih menonaktifkan 2FA, mencabut kode pemulihan dan sesi lain.</label>
<button class="button button-outline button-danger">Nonaktifkan 2FA</button></form></details>
<?php endif; ?>
</section>
<?php if ($recoveryCodes): ?><section class="form-card"><h2>Simpan kode pemulihan sekarang</h2><p>Ditampilkan sekali. Setiap kode hanya bisa digunakan satu kali sebagai pengganti kode autentikator. Simpan di tempat privat, terpisah dari perangkat autentikator.</p><ul><?php foreach ($recoveryCodes as $code): ?><li><code><?= escape($code) ?></code></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if (accountMfaReady($db, $user) && (!isStaff($user) || staffPortalReady($db, $config, $user))): ?><div class="action-row account-settings-footer"><a class="button button-outline" href="/dashboard">Kembali ke dashboard</a></div><?php endif; ?>
<?php endif; ?>
</main></body></html>
