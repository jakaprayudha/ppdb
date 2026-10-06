<?php
declare(strict_types=1);

function admissionCsrf(): void
{
    echo '<input type="hidden" name="csrf" value="' . escape(csrfToken()) . '">';
}

function admissionVersion(int $version): void
{
    echo '<input type="hidden" name="version" value="' . $version . '">';
}

function participantField(string $key, array $data, array $errors, bool $disabled = false): void
{
    [$label, $maximum] = admissionFields()[$key];
    $value = $data[$key] ?? '';
    $optional = in_array($key, ['nisn', 'postal_code', 'source_school'], true);
    $attributes = isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $key . '-error"' : '';
    $attributes .= $disabled ? ' disabled' : '';
    ?>
    <div class="field <?= $key === 'address' ? 'field-wide' : '' ?>">
        <label for="<?= escape($key) ?>"><?= escape($label) ?><?= $optional ? ' <span class="optional-label">(opsional pada draf)</span>' : '' ?></label>
        <?php if (in_array($key, ['sex', 'relationship'], true)):
            $options = $key === 'sex' ? ['L' => 'Laki-laki', 'P' => 'Perempuan'] : ['Ayah' => 'Ayah', 'Ibu' => 'Ibu', 'Wali' => 'Wali']; ?>
            <select id="<?= escape($key) ?>" name="<?= escape($key) ?>"<?= $attributes ?>><option value="">Pilih <?= escape(strtolower($label)) ?></option>
                <?php foreach ($options as $code => $text): ?><option value="<?= escape($code) ?>"<?= $value === $code ? ' selected' : '' ?>><?= escape($text) ?></option><?php endforeach; ?>
            </select>
        <?php elseif ($key === 'address'): ?>
            <textarea id="address" name="address" rows="3" maxlength="<?= $maximum ?>"<?= $attributes ?>><?= escape($value) ?></textarea>
        <?php else: ?>
            <input id="<?= escape($key) ?>" name="<?= escape($key) ?>" type="<?= $key === 'birth_date' ? 'date' : ($key === 'phone' ? 'tel' : 'text') ?>" maxlength="<?= $maximum ?>" value="<?= escape($value) ?>"<?= $attributes ?>
                <?= in_array($key, ['nisn', 'postal_code'], true) ? 'inputmode="numeric"' : '' ?><?= $key === 'birth_date' ? ' max="' . (new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d') . '"' : '' ?>>
        <?php endif; ?>
        <?php if (isset($errors[$key])): ?><p class="field-error" id="<?= escape($key) ?>-error"><?= escape($errors[$key]) ?></p><?php endif; ?>
        <?php if ($key === 'nisn'): ?><p class="field-help field-hint">Kosongkan jika belum memiliki NISN. Jangan memasukkan nomor fiktif pada periode nyata.</p><?php endif; ?>
    </div>
    <?php
}

function participantSummary(array $data): void
{
    echo '<dl class="summary-grid">';
    foreach (admissionFields() as $key => [$label]) {
        $value = $data[$key] ?? '';
        if ($key === 'sex') {
            $value = match ($value) { 'L' => 'Laki-laki', 'P' => 'Perempuan', default => '' };
        }
        echo '<div><dt>' . escape($label) . '</dt><dd>' . escape($value !== '' ? $value : 'Belum diisi') . '</dd></div>';
    }
    echo '</dl>';
}

function applicationCards(array $applications): void
{
    if (!$applications) {
        echo '<div class="empty-state"><h3>Belum ada pendaftaran</h3><p>Tambahkan profil peserta, lalu pilih periode penerimaan untuk membuat draf.</p><a class="text-link" href="/admissions">Lihat periode penerimaan →</a></div>';
        return;
    }
    echo '<div class="application-list">';
    foreach ($applications as $item) {
        $student = admissionData($item['data_json']);
        ?>
        <article class="list-card">
            <div><span class="status-tag <?= $item['status'] === 'submitted' ? 'status-submitted' : '' ?>"><?= $item['status'] === 'submitted' ? 'Terkirim · Menunggu verifikasi' : 'Draf' ?></span>
                <?php if ($item['is_demo']): ?><span class="demo-tag">DEMO</span><?php endif; ?>
                <h3><?= escape($student['name'] ?: 'Peserta belum diisi') ?></h3>
                <p><?= escape($item['school']) ?> · <?= escape($item['academic_year']) ?></p>
                <small>Terakhir disimpan: <?= escape(admissionDate((int) $item['updated_at'], $item['timezone'])) ?></small>
            </div>
            <a class="button button-outline" href="/applications/<?= escape($item['id']) ?><?= $item['status'] === 'submitted' ? '?step=4' : '' ?>"><?= $item['status'] === 'submitted' ? 'Lihat status' : 'Lanjutkan draf' ?> →</a>
        </article>
        <?php
    }
    echo '</div>';
}

$pageTitles = [
    'dashboard' => 'Beranda pendaftar', 'participants' => 'Profil peserta', 'profile' => $profile ? 'Edit profil peserta' : 'Tambah profil peserta',
    'periods' => 'Periode penerimaan', 'new-application' => 'Mulai pendaftaran', 'application' => 'Formulir pendaftaran',
    'receipt' => 'Tanda terima pendaftaran', 'not-found' => 'Halaman tidak tersedia',
];
$title = $pageTitles[$screen];
$studentKeys = ['name', 'nisn', 'sex', 'birth_place', 'birth_date', 'source_school'];
$guardianKeys = ['guardian_name', 'relationship', 'phone', 'address', 'province', 'city', 'district', 'village', 'postal_code'];
?>
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light">
    <title><?= escape($title) ?> — PPDB</title><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script></head>
<body class="dashboard-page">
<a class="skip-link" href="#main">Lewati ke konten</a>
<header class="dashboard-header">
    <a class="brand" href="/dashboard"><span class="brand-mark" aria-hidden="true">P</span><span>PPDB<span class="brand-caption">LAYANAN PENDIDIKAN</span></span></a>
    <div class="header-actions"><span class="role-tag">Akun wali</span><form method="post" action="/logout"><?php admissionCsrf(); ?><button class="button button-outline" type="submit">Keluar</button></form></div>
</header>
<nav class="portal-nav" aria-label="Navigasi pendaftar">
    <a href="/dashboard"<?= $screen === 'dashboard' ? ' aria-current="page"' : '' ?>>Beranda</a>
    <a href="/participants"<?= in_array($screen, ['participants', 'profile'], true) ? ' aria-current="page"' : '' ?>>Profil peserta</a>
    <a href="/admissions"<?= in_array($screen, ['periods', 'new-application', 'application', 'receipt'], true) ? ' aria-current="page"' : '' ?>>Pendaftaran</a>
    <a href="/privacy">Privasi</a>
</nav>
<main id="main" class="dashboard-main admission-main">
    <?php if ($config['environment'] === 'production'): ?><div class="demo-banner">Modul registrasi belum dibuka untuk data nyata. Perubahan data dinonaktifkan pada produksi.</div>
    <?php else: ?><div class="demo-banner"><strong>Lingkungan pengembangan.</strong> Gunakan identitas dan dokumen uji, bukan data pribadi anak yang sebenarnya.</div><?php endif; ?>
    <?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="error-summary" role="alert" tabindex="-1" data-error-summary><strong><?= escape($errors['form']) ?></strong>
        <?php if (count($errors) > 1): ?><ul><?php foreach ($errors as $field => $message): if ($field === 'form') { continue; } ?><li><?= escape($message) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (http_response_code() === 409): ?><p><a href="<?= escape($path . ($screen === 'application' ? '?step=' . $step : '')) ?>">Muat ulang halaman</a></p><?php endif; ?>
    </div><?php endif; ?>

    <?php if ($screen === 'dashboard'): ?>
        <p class="eyebrow">BERANDA PENDAFTAR</p><h1>Halo, <?= escape($user['name']) ?></h1>
        <p class="lead">Kelola profil peserta, lanjutkan draf, dan pantau pendaftaran Anda.</p>
        <div class="stats-grid">
            <div><span>Profil peserta</span><strong><?= count($profiles) ?></strong></div>
            <div><span>Draf pendaftaran</span><strong><?= count(array_filter($applications, fn(array $item): bool => $item['status'] === 'draft')) ?></strong></div>
            <div><span>Pendaftaran terkirim</span><strong><?= count(array_filter($applications, fn(array $item): bool => $item['status'] === 'submitted')) ?></strong></div>
        </div>
        <div class="dashboard-grid">
            <section class="welcome-card"><span class="section-badge">PENDAFTARAN PESERTA DIDIK BARU</span><h2>Siapkan pendaftaran dalam empat langkah</h2>
                <ol class="journey-list">
                    <li><span>1</span><div><strong>Tambahkan profil peserta</strong><small>Satu akun wali dapat mengelola beberapa peserta.</small></div></li>
                    <li><span>2</span><div><strong>Pilih periode dan jalur</strong><small>Periksa jadwal serta persyaratan penyelenggara.</small></div></li>
                    <li><span>3</span><div><strong>Lengkapi data dan dokumen</strong><small>Simpan draf untuk dilanjutkan nanti.</small></div></li>
                    <li><span>4</span><div><strong>Tinjau, kirim, dan simpan tanda terima</strong><small>Status awal: menunggu verifikasi, bukan diterima.</small></div></li>
                </ol><div class="action-row"><a class="button button-primary" href="/participants/new">Tambah peserta →</a><a class="button button-outline" href="/admissions">Pilih periode</a></div>
            </section>
            <aside class="account-card"><h2>Informasi akun wali</h2><dl><dt>Nama</dt><dd><?= escape($user['name']) ?></dd><dt>Email</dt><dd><?= escape($user['email']) ?></dd></dl>
                <p class="account-note">Data profil disalin saat draf dibuat. Perubahan profil tidak mengubah pendaftaran yang sudah dibuat atau dikirim.</p></aside>
        </div>
        <div class="section-heading"><h2>Pendaftaran Anda</h2><a class="text-link" href="/admissions">Lihat periode →</a></div><?php applicationCards($applications); ?>
    <?php elseif ($screen === 'participants'): ?>
        <div class="section-heading"><div><p class="eyebrow">DATA CALON PESERTA DIDIK</p><h1>Profil peserta</h1></div><a class="button button-primary compact-button" href="/participants/new">Tambah peserta +</a></div>
        <p class="lead">Lengkapi data secara bertahap. Data profil disalin ke draf pendaftaran saat Anda memilih periode.</p>
        <?php if (!$profiles): ?><div class="empty-state"><h2>Belum ada profil peserta</h2><p>Tambahkan peserta pertama untuk mulai mendaftar.</p></div><?php endif; ?>
        <div class="profile-grid"><?php foreach ($profiles as $item): $participant = admissionData($item['data_json']); ?>
            <article class="account-card"><span class="section-badge">PROFIL PESERTA</span><h2><?= escape($participant['name']) ?></h2><p class="lead"><?= escape($participant['source_school'] ?: 'Sekolah asal belum diisi') ?></p>
                <div class="action-row"><a class="button button-outline" href="/participants/<?= escape($item['id']) ?>">Edit profil</a><a class="text-link" href="/admissions?profile=<?= escape($item['id']) ?>">Pilih periode →</a></div></article>
        <?php endforeach; ?></div>
    <?php elseif ($screen === 'profile'): ?>
        <p class="eyebrow">PROFIL CALON PESERTA DIDIK</p><h1><?= escape($title) ?></h1><p class="lead">Nama wajib untuk menyimpan profil. Isian lain boleh dilengkapi nanti; kelengkapan akan diperiksa saat pendaftaran dikirim.</p>
        <form method="post" class="form-card" data-draft-form><?php admissionCsrf(); ?><?php if ($profile): admissionVersion((int) $profile['version']); endif; ?>
            <h2>Identitas peserta</h2><div class="form-grid"><?php foreach ($studentKeys as $key): participantField($key, $data, $errors); endforeach; ?></div>
            <h2>Orang tua / wali dan alamat</h2><div class="form-grid"><?php foreach ($guardianKeys as $key): participantField($key, $data, $errors); endforeach; ?></div>
            <p class="field-help">Gunakan tombol simpan. Perubahan profil tidak otomatis disalin ke draf yang sudah dibuat.</p>
            <div class="form-actions"><a class="button button-outline" href="/participants">Kembali</a><button class="button button-primary compact-button" type="submit">Simpan profil →</button></div>
        </form>
        <?php if ($profile): ?><div class="notice">Profil tersedia. <a href="/admissions?profile=<?= escape($profile['id']) ?>">Pilih periode penerimaan untuk peserta ini →</a></div><?php endif; ?>
    <?php elseif ($screen === 'periods'): ?>
        <p class="eyebrow">INFORMASI PENERIMAAN</p><h1>Periode penerimaan</h1><p class="lead">Setiap periode berlaku untuk satu sekolah dan satu pilihan. Persyaratan mengikuti konfigurasi penyelenggara, bukan aturan seleksi otomatis.</p>
        <?php if (!$periods): ?><div class="empty-state"><h2>Belum ada periode penerimaan</h2><p>Pengelola perlu menyiapkan jadwal dan persyaratan melalui konfigurasi admin lokal.</p></div><?php endif; ?>
        <div class="profile-grid"><?php foreach ($periods as $item): $configuration = admissionData($item['config_json']); ?>
            <article class="period-card">
                <?php if ($item['is_demo']): ?><span class="demo-tag">DEMO · BUKAN PENERIMAAN NYATA</span><?php endif; ?>
                <span class="status-tag"><?= escape(periodState($item)) ?></span><h2><?= escape($item['school']) ?></h2><p><?= escape($item['organizer']) ?></p><span class="section-badge"><?= escape(admissionModeLabel($configuration)) ?></span>
                <dl><dt>Jenjang / tahun ajaran</dt><dd><?= escape($item['level'] . ' · ' . $item['academic_year']) ?></dd><dt>Pendaftaran</dt><dd><?= escape(admissionDate((int) $item['opens_at'], $item['timezone'])) ?><br>sampai <?= escape(admissionDate((int) $item['closes_at'], $item['timezone'])) ?></dd><dt>Jalur tersedia</dt><dd><?= escape(implode(', ', array_column($configuration['pathways'], 'name'))) ?></dd><dt>Rujukan ketentuan</dt><dd><?= escape($configuration['rule_reference']) ?></dd><dt>Bantuan</dt><dd><?= escape($configuration['help_contact']) ?></dd></dl>
                <?php if (periodState($item) === 'Pendaftaran dibuka'): ?><a class="button button-primary" href="/applications/new?period=<?= escape($item['id']) ?>&amp;profile=<?= escape(admissionQuery('profile')) ?>">Mulai pendaftaran →</a><?php endif; ?>
            </article>
        <?php endforeach; ?></div><div class="section-heading"><h2>Pendaftaran Anda</h2></div><?php applicationCards($applications); ?>
    <?php elseif ($screen === 'new-application' && $period): ?>
        <p class="eyebrow">DRAF PENDAFTARAN BARU</p><h1>Mulai pendaftaran</h1><p class="lead"><?= escape($period['school'] . ' · ' . $period['academic_year']) ?></p>
        <span class="section-badge"><?= escape(admissionModeLabel($period['configuration'])) ?></span>
        <div class="requirement-note"><h3>Ketentuan pemilihan jalur</h3><p><?= escape($period['configuration']['rule_reference']) ?></p>
            <?php if (($period['configuration']['admission_mode'] ?? '') === 'public_spmb'): ?><p>Domisili adalah istilah pengganti zonasi. Afirmasi dan mutasi memiliki kategori bukti berbeda. Pilihan jalur tidak berarti kelayakan sudah diverifikasi.</p><?php endif; ?>
        </div>
        <?php if ($period['is_demo']): ?><div class="demo-banner">Periode DEMO. Jangan mengunggah dokumen atau identitas asli.</div><?php endif; ?>
        <?php if (!$profiles): ?><div class="empty-state"><h2>Tambahkan profil peserta terlebih dahulu</h2><a class="button button-primary compact-button" href="/participants/new">Tambah peserta →</a></div>
        <?php else: ?>
            <form method="post" class="form-card"><?php admissionCsrf(); ?><input type="hidden" name="period_id" value="<?= escape($period['id']) ?>">
                <div class="field"><label for="profile_id">Pilih peserta</label><select id="profile_id" name="profile_id" required><option value="">Pilih profil peserta</option><?php foreach ($profiles as $item): ?><option value="<?= escape($item['id']) ?>"<?= $selectedProfile === $item['id'] ? ' selected' : '' ?>><?= escape(admissionData($item['data_json'])['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="pathway">Pilih jalur penerimaan</label><select name="pathway" id="pathway" required><option value="">Pilih jalur</option><?php foreach ($period['configuration']['pathways'] as $item): ?><option value="<?= escape($item['code']) ?>"<?= $selectedPathway === $item['code'] ? ' selected' : '' ?>><?= escape($item['name']) ?></option><?php endforeach; ?></select></div>
                <?php foreach ($period['configuration']['pathways'] as $item): ?><div class="requirement-note"><h3><?= escape($item['name']) ?></h3><p><?= escape($item['description']) ?></p><ul><?php foreach ($item['documents'] as $doc): ?><li><?= escape($doc['label']) ?> — <?= $doc['required'] ? 'wajib' : 'opsional' ?></li><?php endforeach; ?></ul></div><?php endforeach; ?>
                <p class="field-help">Satu draf per peserta dan periode. Jika sudah ada, draf yang sama akan dibuka. Data profil disalin saat draf pertama kali dibuat.</p><div class="form-actions"><a class="button button-outline" href="/admissions">Kembali</a><button class="button button-primary compact-button" type="submit">Buat / lanjutkan draf →</button></div>
            </form>
        <?php endif; ?>
    <?php elseif (in_array($screen, ['application', 'receipt'], true) && $application && $period):
        $pathway = pathwayConfig($period, $application['pathway']);
        $base = '/applications/' . $application['id']; ?>
        <div class="section-heading"><div><p class="eyebrow"><?= $screen === 'receipt' ? 'BUKTI PENGIRIMAN' : 'REGISTRASI PESERTA DIDIK' ?></p><h1><?= escape($title) ?></h1></div><span class="status-tag"><?= $application['status'] === 'submitted' ? 'Terkirim · Menunggu verifikasi' : 'Draf' ?></span></div>
        <p class="lead"><?= escape($period['school'] . ' · ' . $period['academic_year'] . ' · ' . $pathway['name']) ?></p>
        <?php if ($period['is_demo']): ?><div class="demo-banner">DEMO · Pendaftaran ini untuk pengujian dan bukan penerimaan nyata.</div><?php endif; ?>
        <?php if ($screen === 'application' && $application['status'] === 'draft'): ?>
            <nav class="step-nav" aria-label="Langkah pendaftaran"><?php foreach ([1 => 'Data peserta', 2 => 'Wali & jalur', 3 => 'Dokumen', 4 => 'Tinjau & kirim'] as $number => $label): ?><a href="<?= escape($base . '?step=' . $number) ?>"<?= $step === $number ? ' aria-current="step"' : '' ?>><span><?= $number ?></span><?= escape($label) ?></a><?php endforeach; ?></nav>
            <p class="save-state">Terakhir tersimpan: <?= escape(admissionDate((int) $application['updated_at'], $period['timezone'])) ?>. Simpan perubahan sebelum berpindah langkah.</p>
            <?php if ($readonly): ?><div class="notice">Draf hanya dapat dibaca. <?= escape(periodState($period)) ?>.</div><?php endif; ?>
            <?php if ($step <= 2): ?>
                <form method="post" class="form-card" data-draft-form><?php admissionCsrf(); admissionVersion((int) $application['version']); ?><input type="hidden" name="action" value="save">
                    <h2><?= $step === 1 ? 'Identitas calon peserta didik' : 'Data orang tua / wali dan alamat' ?></h2><p class="field-help">Isian boleh belum lengkap untuk menyimpan draf. Data wajib akan diperiksa saat pengiriman.<?= $step === 1 && $period['level'] !== 'SD' ? ' Sekolah asal wajib untuk pengiriman ke SMP/SMA.' : '' ?></p>
                    <div class="form-grid"><?php foreach ($step === 1 ? $studentKeys : $guardianKeys as $key): participantField($key, $data, $errors, $readonly); endforeach; ?></div>
                    <?php if ($step === 2): ?><div class="field"><label for="pathway">Jalur penerimaan</label><select name="pathway" id="pathway"<?= $readonly ? ' disabled' : '' ?>><?php foreach ($period['configuration']['pathways'] as $item): ?><option value="<?= escape($item['code']) ?>"<?= $selectedPathway === $item['code'] ? ' selected' : '' ?>><?= escape($item['name']) ?></option><?php endforeach; ?></select><p class="field-help field-hint">Jika jalur berubah, periksa ulang checklist dokumen di langkah 3.</p></div><?php endif; ?>
                    <div class="form-actions"><a class="button button-outline" href="<?= escape($step === 1 ? '/admissions' : $base . '?step=1') ?>">Kembali</a>
                        <?php if (!$readonly): ?><button class="button button-outline" type="submit" name="next" value="0">Simpan draf</button><button class="button button-primary compact-button" type="submit" name="next" value="1">Simpan & lanjut →</button><?php else: ?><a class="button button-outline" href="<?= escape($base . '?step=' . ($step + 1)) ?>">Langkah berikutnya →</a><?php endif; ?></div>
                </form>
            <?php elseif ($step === 3): ?>
                <section class="form-card"><h2>Dokumen persyaratan</h2><p class="lead">PDF, JPG, atau PNG asli. Maksimal 2 MB per berkas dan 20 megapiksel per gambar. Unggah satu dokumen setiap kali.</p>
                    <?php foreach ($pathway['documents'] as $requirement): $document = $documents[$requirement['code']] ?? null; ?>
                        <article class="document-card"><div class="section-heading"><h3><?= escape($requirement['label']) ?></h3><span class="status-tag"><?= $document ? 'Sudah diunggah' : ($requirement['required'] ? 'Wajib · Belum diunggah' : 'Opsional') ?></span></div>
                            <?php if ($document): ?><p><?= escape($document['original_name']) ?> · <?= number_format($document['size'] / 1024, 1) ?> KB</p>
                                <?php if ($document['mime_type'] !== 'application/pdf'): ?><img class="document-preview" src="/documents/<?= escape($document['id']) ?>" alt="Pratinjau <?= escape($requirement['label']) ?>" loading="lazy"><?php endif; ?>
                                <div class="action-row"><a class="text-link" href="/documents/<?= escape($document['id']) ?>?download=1">Unduh <?= escape($document['mime_type'] === 'application/pdf' ? 'PDF untuk ditinjau' : 'dokumen') ?> ↓</a>
                                    <?php if (!$readonly): ?><form method="post"><?php admissionCsrf(); admissionVersion((int) $application['version']); ?><input type="hidden" name="action" value="remove-document"><input type="hidden" name="document_id" value="<?= escape($document['id']) ?>"><button class="button button-outline" type="submit">Hapus dari checklist</button></form><?php endif; ?></div>
                            <?php endif; ?>
                            <?php if (!$readonly): ?><form method="post" enctype="multipart/form-data" class="upload-form"><?php admissionCsrf(); admissionVersion((int) $application['version']); ?><input type="hidden" name="action" value="upload"><input type="hidden" name="kind" value="<?= escape($requirement['code']) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                                <div class="field"><label for="file-<?= escape($requirement['code']) ?>"><?= $document ? 'Ganti berkas' : 'Pilih berkas' ?></label><input id="file-<?= escape($requirement['code']) ?>" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required data-file-limit="2097152"><p class="field-help field-hint" data-file-status aria-live="polite">Berkas baru disimpan setelah tombol unggah ditekan.</p></div><button class="button button-outline" type="submit"><?= $document ? 'Unggah pengganti' : 'Unggah dokumen' ?> ↑</button></form><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$pathway['documents']): ?><p>Tidak ada persyaratan dokumen untuk jalur ini.</p><?php endif; ?>
                    <div class="form-actions"><a class="button button-outline" href="<?= escape($base . '?step=2') ?>">Kembali</a><a class="button button-primary compact-button" href="<?= escape($base . '?step=4') ?>">Tinjau pendaftaran →</a></div>
                </section>
            <?php else: ?>
                <section class="form-card"><h2>Periksa data sebelum mengirim</h2><?php participantSummary($data); ?>
                    <h2>Checklist dokumen</h2><ul class="checklist"><?php foreach ($pathway['documents'] as $requirement): ?><li><?= escape($requirement['label']) ?> — <?= isset($documents[$requirement['code']]) ? 'Sudah diunggah' : ($requirement['required'] ? 'WAJIB: belum diunggah' : 'Opsional: belum diunggah') ?></li><?php endforeach; ?></ul>
                    <div class="requirement-note"><h3>Pemberitahuan penyelenggara</h3><p><?= escape($period['configuration']['privacy_notice']) ?></p><p>Rujukan: <?= escape($period['configuration']['rule_reference']) ?></p><p>Bantuan: <?= escape($period['configuration']['help_contact']) ?></p></div>
                    <p class="lead">Setelah dikirim, data dan dokumen dikunci. Pengiriman bukan jaminan diterima. Panitia masih perlu memverifikasi berkas.</p>
                    <?php if (!$readonly): ?><form method="post"><?php admissionCsrf(); admissionVersion((int) $application['version']); ?><input type="hidden" name="action" value="submit">
                        <div class="privacy-check"><input type="checkbox" name="declaration" id="declaration" value="1" required><label for="declaration">Saya orang tua / wali yang berwenang, telah membaca pemberitahuan privasi, dan menyatakan data serta dokumen yang dikirim benar. Untuk periode demo, saya hanya menggunakan data uji.</label></div>
                        <div class="form-actions"><a class="button button-outline" href="<?= escape($base . '?step=3') ?>">Kembali ke dokumen</a><button class="button button-primary compact-button" type="submit">Kirim pendaftaran →</button></div>
                    </form><?php endif; ?>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <section class="form-card receipt-card">
                <span class="section-badge">PENDAFTARAN TELAH DIKIRIM</span><?php if ($period['is_demo']): ?> <span class="demo-tag">DEMO · BUKAN PENERIMAAN NYATA</span><?php endif; ?><h2>Tanda terima pendaftaran</h2><p class="registration-number"><?= escape($application['registration_number'] ?? '') ?></p>
                <dl class="summary-grid"><div><dt>Peserta</dt><dd><?= escape($data['name']) ?></dd></div><div><dt>Sekolah / tahun ajaran</dt><dd><?= escape($period['school'] . ' · ' . $period['academic_year']) ?></dd></div><div><dt>Jalur</dt><dd><?= escape($pathway['name']) ?></dd></div><div><dt>Dikirim pada</dt><dd><?= escape(admissionDate((int) $application['submitted_at'], $period['timezone'])) ?></dd></div></dl>
                <div class="notice">Status: terkirim, menunggu verifikasi. Tanda terima ini bukan bukti diterima di sekolah. Panel verifikasi panitia belum tersedia pada tahap ini.</div>
                <div class="action-row print-hide"><button type="button" class="button button-primary compact-button" data-print hidden>Cetak / simpan PDF ↓</button><a class="button button-outline" href="/admissions">Pendaftaran lainnya</a></div>
                <h2>Data yang dikirim</h2><?php participantSummary($data); ?>
                <h2>Dokumen yang dikirim</h2><ul class="checklist"><?php foreach ($pathway['documents'] as $requirement): $document = $documents[$requirement['code']] ?? null; ?><li><?= escape($requirement['label']) ?> — <?= $document ? escape($document['original_name']) : 'Tidak diunggah (opsional)' ?><?php if ($document): ?> <a class="text-link print-hide" href="/documents/<?= escape($document['id']) ?>?download=1">Unduh</a><?php endif; ?></li><?php endforeach; ?></ul>
                <h2>Riwayat aktivitas</h2><p class="lead">Maksimal 30 aktivitas terakhir. Tidak ada keputusan seleksi pada tahap ini.</p>
                <ol class="activity-list"><?php foreach ($events as $event): ?><li><strong><?= escape(match ($event['action']) {
                    'draft_created' => 'Draf dibuat', 'draft_saved' => 'Draf disimpan', 'document_uploaded' => 'Dokumen diunggah',
                    'document_removed' => 'Dokumen dihapus dari checklist', 'submitted' => 'Pendaftaran dikirim',
                }) ?></strong><span><?= escape(admissionDate((int) $event['created_at'], $period['timezone'])) ?></span></li><?php endforeach; ?></ol>
            </section>
        <?php endif; ?>
    <?php else: ?><h1>Halaman tidak tersedia</h1><a class="button button-outline" href="/dashboard">Kembali ke beranda</a><?php endif; ?>
</main>
<footer class="dashboard-footer">PPDB · Layanan Penerimaan Peserta Didik Baru</footer>
</body>
</html>
