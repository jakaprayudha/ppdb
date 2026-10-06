<?php
declare(strict_types=1);

$titles = [
    'login' => 'Selamat datang kembali',
    'register' => 'Daftar akun PPDB',
    'forgot-password' => 'Lupa password?',
    'reset-password' => 'Buat password baru',
    'dashboard' => 'Beranda pendaftar',
    'privacy' => 'Pemberitahuan privasi',
    'logout' => 'Keluar dari akun',
    'not-found' => 'Halaman tidak ditemukan',
];
$title = $titles[$page];
$descriptions = [
    'login' => 'Masuk ke akun Anda untuk mengakses layanan penerimaan peserta didik baru.',
    'register' => 'Buat akun orang tua atau wali untuk mengakses layanan pendaftaran peserta didik baru.',
    'forgot-password' => 'Tidak perlu khawatir. Masukkan email akun Anda untuk menerima tautan pemulihan.',
    'reset-password' => 'Gunakan password yang unik dan tidak dipakai di layanan lain.',
];
function fieldError(string $field, array $errors): void
{
    if (isset($errors[$field])) {
        echo '<p class="field-error" id="' . escape($field) . '-error">' . escape($errors[$field]) . '</p>';
    }
}
function fieldAttributes(string $field, array $errors): string
{
    return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . escape($field) . '-error"' : '';
}
function passwordField(string $label, string $name, array $errors, bool $new = false): void
{
    $errorField = $name === 'password_confirmation' ? 'password' : $name;
    ?>
    <div class="field">
        <label for="<?= escape($name) ?>"><?= escape($label) ?></label>
        <div class="password-input">
            <input type="password" id="<?= escape($name) ?>" name="<?= escape($name) ?>" required
                autocomplete="<?= $new ? 'new-password' : 'current-password' ?>"
                <?= $new ? 'minlength="12"' : '' ?> maxlength="72"<?= fieldAttributes($errorField, $errors) ?>
                <?= $new && !isset($errors[$errorField]) ? ' aria-describedby="password-help"' : '' ?>>
            <button class="password-toggle" type="button" data-password="<?= escape($name) ?>" aria-controls="<?= escape($name) ?>" aria-pressed="false" hidden>Tampilkan<span class="sr-only"> <?= escape(strtolower($label)) ?></span></button>
        </div>
        <?php if ($name === 'password') { fieldError('password', $errors); } ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= escape($title) ?> — PPDB</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body class="<?= $page === 'dashboard' ? 'dashboard-page' : 'auth-page' ?>">
<a class="skip-link" href="#main">Lewati ke konten</a>
<?php if ($page === 'dashboard'): ?>
    <header class="dashboard-header">
        <a class="brand" href="/dashboard"><span class="brand-mark" aria-hidden="true">P</span><span>PPDB<span class="brand-caption">LAYANAN PENDIDIKAN</span></span></a>
        <div class="header-actions"><span class="role-tag">Akun wali</span>
            <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><button class="button button-outline" type="submit">Keluar</button></form>
        </div>
    </header>
    <main id="main" class="dashboard-main">
        <p class="eyebrow">BERANDA PENDAFTAR</p>
        <h1>Halo, <?= escape($user['name']) ?> <span class="greeting-dot" aria-hidden="true">•</span></h1>
        <p class="lead">Akun Anda siap digunakan untuk mengakses layanan penerimaan peserta didik baru.</p>
        <?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
        <div class="dashboard-grid">
            <section class="welcome-card">
                <span class="section-badge">LANGKAH PERTAMA SELESAI</span>
                <h2>Persiapan pendaftaran peserta didik baru</h2>
                <p>Pembuatan akun wali sudah selesai. Modul profil calon murid dan pendaftaran akan tersedia setelah pengaturan penerimaan disiapkan.</p>
                <ol class="journey-list">
                    <li class="completed"><span aria-hidden="true">✓</span><div><strong>Buat akun wali</strong><small>Selesai — Anda telah terdaftar</small></div></li>
                    <li><span aria-hidden="true">2</span><div><strong>Lengkapi profil calon murid</strong><small>Belum tersedia</small></div></li>
                    <li><span aria-hidden="true">3</span><div><strong>Pilih periode dan jalur</strong><small>Menunggu pengaturan penerimaan</small></div></li>
                    <li><span aria-hidden="true">4</span><div><strong>Kirim formulir dan dokumen</strong><small>Belum tersedia</small></div></li>
                </ol>
            </section>
            <aside class="account-card">
                <div class="avatar" aria-hidden="true"><?= escape(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></div>
                <h2>Informasi akun</h2>
                <dl><dt>Nama wali</dt><dd><?= escape($user['name']) ?></dd><dt>Email</dt><dd><?= escape($user['email']) ?></dd><dt>Peran</dt><dd>Wali / pendaftar</dd></dl>
                <p class="account-note">Jangan bagikan password atau tautan pemulihan akun kepada siapa pun.</p>
                <a class="text-link" href="/privacy">Baca pemberitahuan privasi <span aria-hidden="true">↗</span></a>
            </aside>
        </div>
    </main>
    <footer class="dashboard-footer">PPDB · Layanan Penerimaan Peserta Didik Baru</footer>
<?php else: ?>
    <div class="auth-shell">
        <aside class="story-panel" aria-label="Tentang PPDB">
            <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">P</span><span>PPDB<span class="brand-caption">LAYANAN PENDIDIKAN</span></span></a>
            <div class="story-content">
                <span class="story-kicker"><span aria-hidden="true"></span> PORTAL RESMI PEMERINTAH</span>
                <h2>Penerimaan <br>Peserta Didik <br><em>Baru</em></h2>
                <p>Layanan pendaftaran peserta didik baru secara daring. Akses informasi dan tahapan penerimaan sesuai ketentuan yang berlaku.</p>
                <div class="school-art" aria-hidden="true">
                    <div class="art-sun"></div><div class="art-cloud"></div>
                    <div class="art-building"><div class="art-roof"></div><div class="art-clock"></div><div class="art-windows"><i></i><i></i><i></i><i></i></div><div class="art-door"></div></div>
                    <div class="art-tree tree-left"></div><div class="art-tree tree-right"></div><div class="art-ground"></div>
                    <span class="art-caption">LAYANAN PENDIDIKAN TERPADU</span>
                </div>
            </div>
            <div class="story-footer"><span class="tiny-shield" aria-hidden="true">◇</span> Jaga kerahasiaan akun dan data pribadi Anda.</div>
        </aside>
        <div class="form-panel">
            <div class="top-note">Penerimaan peserta didik baru <span aria-hidden="true">/</span> <strong><?= $page === 'register' ? 'Daftar akun' : ($page === 'privacy' ? 'Privasi' : 'Akses akun') ?></strong></div>
            <main id="main" class="form-content <?= $page === 'privacy' ? 'privacy-content' : '' ?>">
                <?php if (in_array($page, ['forgot-password', 'reset-password'], true)): ?><a class="back-link" href="/login"><span aria-hidden="true">←</span> Kembali ke masuk</a><?php endif; ?>
                <span class="section-badge"><?= $page === 'register' ? 'AKUN WALI / PENDAFTAR' : 'PORTAL PENDAFTAR' ?></span>
                <h1><?= escape($title) ?><?php if (!str_ends_with($title, '?')): ?><span class="heading-dot" aria-hidden="true">.</span><?php endif; ?></h1>
                <?php if (isset($descriptions[$page])): ?><p class="intro"><?= escape($descriptions[$page]) ?></p><?php endif; ?>
                <?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
                <?php if ($errors): ?>
                    <div class="error-summary" role="alert" tabindex="-1" data-error-summary>
                        <strong><?= isset($errors['form']) ? escape($errors['form']) : 'Periksa kembali data Anda.' ?></strong>
                        <?php if (count(array_diff_key($errors, ['form' => true]))): ?><ul><?php foreach ($errors as $field => $message): if ($field === 'form') { continue; } ?><li><a href="#<?= escape($field) ?>"><?= escape($message) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (in_array($page, ['login', 'register', 'forgot-password', 'reset-password'], true) && !$resetInvalid): ?>
                    <form method="post" action="<?= escape('/' . $page . ($page === 'reset-password' ? '?token=' . rawurlencode($resetToken) : '')) ?>" class="auth-form">
                        <input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>">
                        <?php if ($page === 'register'): ?>
                            <div class="field"><label for="name">Nama lengkap wali</label><input id="name" name="name" type="text" placeholder="Nama sesuai identitas" autocomplete="name" minlength="2" maxlength="100" value="<?= escape($values['name']) ?>" required<?= fieldAttributes('name', $errors) ?>><?php fieldError('name', $errors); ?></div>
                        <?php endif; ?>
                        <?php if ($page !== 'reset-password'): ?>
                            <div class="field"><label for="email">Alamat email</label><input id="email" name="email" type="email" placeholder="nama@email.com" autocomplete="<?= $page === 'login' ? 'username' : 'email' ?>" maxlength="254" value="<?= escape($values['email']) ?>" required<?= fieldAttributes('email', $errors) ?>><?php fieldError('email', $errors); ?></div>
                        <?php endif; ?>
                        <?php if ($page !== 'forgot-password'): passwordField($page === 'reset-password' ? 'Password baru' : 'Password', 'password', $errors, $page !== 'login'); endif; ?>
                        <?php if (in_array($page, ['register', 'reset-password'], true)): ?>
                            <p class="field-help" id="password-help">Minimal 12 karakter, maksimal 72 byte. Sebaiknya gunakan frasa panjang yang mudah Anda ingat.</p>
                            <?php passwordField('Konfirmasi password', 'password_confirmation', $errors, true); ?>
                        <?php endif; ?>
                        <?php if ($page === 'login'): ?><div class="forgot-row"><span class="session-note">Akses pribadi dan aman</span><a class="text-link" href="/forgot-password">Lupa password?</a></div><?php endif; ?>
                        <?php if ($page === 'register'): ?>
                            <div class="privacy-check"><input type="checkbox" id="privacy" name="privacy" value="1" required<?= input('privacy') === '1' ? ' checked' : '' ?><?= fieldAttributes('privacy', $errors) ?>><label for="privacy">Saya telah membaca <a href="/privacy" target="_blank" rel="noopener">pemberitahuan privasi<span class="sr-only"> (tab baru)</span></a> dan memahami penggunaan data akun saya.</label></div>
                            <?php fieldError('privacy', $errors); ?>
                        <?php endif; ?>
                        <button class="button button-primary" type="submit"><?= match ($page) { 'register' => 'Buat akun', 'forgot-password' => 'Kirim tautan pemulihan', 'reset-password' => 'Simpan password baru', default => 'Masuk ke akun' } ?> <span aria-hidden="true">→</span></button>
                    </form>
                    <?php if ($page === 'login'): ?><p class="switch-auth">Belum punya akun? <a href="/register">Daftar sekarang</a></p><?php elseif ($page === 'register'): ?><p class="switch-auth">Sudah punya akun? <a href="/login">Masuk di sini</a></p><?php endif; ?>
                    <div class="safe-note"><span aria-hidden="true">◇</span><p><?= $page === 'forgot-password' ? 'Tautan berlaku selama 30 menit dan hanya dapat digunakan sekali.' : 'Akun ini untuk wali atau calon murid. Akses panitia dikelola terpisah oleh penyelenggara.' ?></p></div>
                <?php elseif ($resetInvalid): ?>
                    <div class="error-summary" role="alert">Tautan tidak valid, sudah digunakan, atau telah kedaluwarsa.</div><a class="button button-primary" href="/forgot-password">Minta tautan baru <span aria-hidden="true">→</span></a>
                <?php elseif ($page === 'privacy'): ?>
                    <div class="privacy-copy">
                        <p>Aplikasi ini sedang dalam tahap pengembangan. Gunakan data uji, bukan data pribadi anak yang sebenarnya.</p>
                        <h2>Data registrasi peserta</h2><p>Modul registrasi menyimpan profil peserta, data orang tua / wali dan alamat, pilihan periode serta jalur, dokumen persyaratan, tanda terima, dan catatan perubahan. Data hanya dapat diakses melalui akun wali pemiliknya. Persyaratan dokumen dan pemberitahuan penyelenggara ditampilkan sebelum pengiriman.</p>
                        <p>Perubahan profil tidak otomatis mengubah draf yang sudah dibuat. Setelah dikirim, data dan dokumen dikunci. Dokumen yang diganti atau dihapus dari checklist tetap disimpan secara privat untuk audit sampai pengelola menjalankan kebijakan retensi.</p>
                        <h2>Data akun yang disimpan</h2><p>Nama wali, email, hash password, waktu pembuatan akun, waktu Anda membaca pemberitahuan ini, dan catatan aktivitas autentikasi. Password asli tidak disimpan.</p>
                        <h2>Tujuan penggunaan</h2><p>Data digunakan untuk menyediakan akun, autentikasi, pemulihan password, dan perlindungan akses. Data akun tidak digunakan untuk iklan.</p>
                        <h2>Session dan pemulihan</h2><p>Cookie sesi diperlukan untuk login dan perlindungan formulir. Sesi berakhir setelah 30 menit tidak aktif atau maksimal 12 jam. Tautan reset berlaku 30 menit. Penggantian password mengakhiri sesi lama.</p>
                        <h2>Penyimpanan dan pengelola</h2><p>Data disimpan pada server penyelenggara. Pada pengembangan lokal, email pemulihan disimpan di folder privat yang hanya boleh diakses pengembang.</p>
                        <h2>Sebelum digunakan untuk penerimaan nyata</h2><p>Penyelenggara wajib menetapkan identitas dan kontak pengelola data, dasar pemrosesan, kebijakan retensi, prosedur akses/koreksi/penghapusan data, serta pemberitahuan privasi final. Versi pengembangan ini belum menerima pendaftaran murid.</p>
                    </div><a class="text-link" href="<?= $user ? '/dashboard' : '/register' ?>">← Kembali</a>
                <?php elseif ($page === 'not-found'): ?><p class="intro">Alamat yang Anda buka tidak tersedia.</p><a class="button button-primary" href="<?= $user ? '/dashboard' : '/login' ?>">Kembali ke beranda</a>
                <?php endif; ?>
            </main>
            <footer class="form-footer"><span>© <?= date('Y') ?> PPDB</span><a href="/privacy">Privasi &amp; perlindungan data</a></footer>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
