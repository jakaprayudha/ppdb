<?php
declare(strict_types=1);
require __DIR__ . '/participant_summary.php';
$titles = ['dashboard' => 'Dashboard ' . (isStaff($user) ? strtolower(staffRoleLabel($user['role'])) : 'admin'), 'applications' => 'Verifikasi pendaftaran', 'master' => 'Master data',
    'accounts' => 'Akun & akses sekolah', 'audit' => 'Audit aktivitas', 'review' => 'Pemeriksaan peserta', 'not-found' => 'Halaman tidak tersedia'];
$title = $titles[$screen];
$navigation = ['/admin' => ['dashboard', 'Dashboard'], '/admin/applications' => ['applications', 'Verifikasi'],
    '/admin/master-data' => ['master', 'Master data'], '/admin/accounts' => ['accounts', 'Akun & akses'], '/admin/audit' => ['audit', 'Audit']];
if ($user['role'] !== 'central_admin') {
    unset($navigation['/admin/master-data'], $navigation['/admin/accounts'], $navigation['/admin/audit']);
}
$navigation['/account/security'] = ['security', 'Profil akun'];
?>
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= escape($title) ?> — PPDB</title>
<link rel="stylesheet" href="<?= escape(assetUrl('app.css')) ?>"><script src="<?= escape(assetUrl('app.js')) ?>" defer></script><script src="<?= escape(assetUrl('master.js')) ?>" defer></script></head>
<body class="dashboard-page">
<a class="skip-link" href="#main">Lewati ke konten</a>
<div class="portal-topbar">
    <header class="dashboard-header"><a class="brand" href="/admin"><span class="brand-mark" aria-hidden="true">P</span><span>PPDB<span class="brand-caption"><?= escape(isStaff($user) ? strtoupper(staffRoleLabel($user['role'])) : 'ADMIN') ?></span></span></a>
        <div class="header-actions"><span class="role-tag"><?= escape($user['name']) ?></span><form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><button class="button button-outline" type="submit">Keluar</button></form></div>
    </header>
    <?php if ($adminAllowed): ?><nav class="portal-nav admin-nav" aria-label="Navigasi admin"><?php foreach ($navigation as $url => [$key, $label]): ?>
        <?php if ($key === 'master'): ?><details class="master-dropdown"><summary<?= $screen === 'master' ? ' class="active-menu"' : '' ?>>Master data</summary><div class="master-dropdown-menu"><a href="/admin/master-data/periods">Periode pendaftaran</a><a href="/admin/master-data/schools">Sekolah</a></div></details>
        <?php else: ?><a href="<?= escape($url) ?>"<?= ($screen === $key || ($key === 'applications' && $screen === 'review')) ? ' aria-current="page"' : '' ?>><?= escape($label) ?></a><?php endif; ?>
    <?php endforeach; ?></nav><?php endif; ?>
</div>
<main id="main" class="dashboard-main admission-main">
    <p class="eyebrow">PENGELOLAAN PENERIMAAN</p><h1><?= escape($title) ?></h1>
    <?php if ($notice): ?><div class="notice" role="status"><?= escape($notice) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="error-summary" role="alert" tabindex="-1" data-error-summary><?= escape($errors['form']) ?><?php if (http_response_code() === 409): ?><p><a href="<?= escape($path) ?>"><?= $screen === 'master' ? 'Muat ulang data' : 'Muat ulang verifikasi' ?></a></p><?php endif; ?></div><?php endif; ?>
    <?php if ($screen === 'dashboard'): ?>
        <p class="lead">Pantau pendaftaran dan antrean pemeriksaan berkas <?= $user['role'] === 'central_admin' ? 'seluruh sekolah' : 'sesuai sekolah dan penugasan Anda' ?>.</p>
        <div class="stats-grid admin-stats">
            <?php foreach (['drafts' => 'Draf (belum dapat diperiksa)', 'submitted' => 'Pendaftaran terkirim', 'pending' => 'Menunggu verifikasi',
                'valid' => 'Valid', 'needs_correction' => 'Perlu perbaikan', 'invalid' => 'Tidak valid'] as $key => $label): ?>
                <div><span><?= escape($label) ?></span><strong><?= (int) ($stats[$key] ?? 0) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <section class="form-card"><h2>Panel kerja <?= escape(strtolower(staffRoleLabel($user['role']))) ?></h2><p><?= count(array_filter($periods, fn(array $p): bool => (int) $p['enabled'] === 1)) ?> periode aktif di katalog. Pemeriksaan hanya untuk pendaftaran terkirim sesuai kewenangan, termasuk periode diarsipkan.</p>
            <div class="action-row"><a class="button button-primary compact-button" href="/admin/applications?status=pending">Periksa antrean</a><?php if ($user['role'] === 'central_admin'): ?><a class="button button-outline" href="/admin/master-data">Lihat master data</a><?php endif; ?></div>
        </section>
    <?php elseif ($screen === 'applications'): ?>
        <form method="get" class="form-card">
            <div class="form-grid">
                <div class="field"><label for="q">Cari peserta / nomor pendaftaran</label><input id="q" name="q" value="<?= escape($query) ?>"></div>
                <div class="field"><label for="period">Sekolah / periode</label><select id="period" name="period"><option value="">Semua sekolah</option><?php foreach ($periods as $item): ?><option value="<?= escape($item['id']) ?>"<?= $schoolFilter === $item['id'] ? ' selected' : '' ?>><?= escape(admissionDisplayText($item['school']) . ' · ' . $item['academic_year']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="status">Status verifikasi</label><select id="status" name="status"><option value="">Semua status</option><?php foreach (['pending', 'valid', 'needs_correction', 'invalid'] as $state): ?><option value="<?= escape($state) ?>"<?= $statusFilter === $state ? ' selected' : '' ?>><?= escape(verificationLabel($state)) ?></option><?php endforeach; ?></select></div>
            </div><div class="action-row"><button class="button button-primary compact-button" type="submit">Terapkan filter</button><a class="text-link" href="/admin/applications">Reset filter</a><span><?= $total ?> pendaftaran · halaman <?= $page ?></span></div>
        </form>
        <div class="application-list admin-list"><?php foreach ($rows as $row): $student = admissionData($row['data_json']); ?>
            <article class="list-card"><div><span class="status-tag"><?= escape(verificationLabel($row['verification_status'])) ?></span><h3><?= escape($student['name']) ?></h3><p><?= escape(admissionDisplayText($row['school'])) ?></p><small><?= escape($row['registration_number']) ?></small></div><a class="button button-outline" href="/admin/applications/<?= escape($row['id']) ?>">Periksa peserta</a></article>
        <?php endforeach; ?></div>
        <?php if (!$rows): ?><div class="empty-state">Tidak ada pendaftaran terkirim yang cocok.</div><?php endif; ?>
        <div class="action-row"><?php foreach ([$page - 1 => 'Sebelumnya', $page + 1 => 'Berikutnya'] as $number => $label): if ($number < 1 || ($number > $page && $page * 25 >= $total)) continue; ?><a class="button button-outline" href="/admin/applications?<?= escape(http_build_query(['q' => $query, 'period' => $schoolFilter, 'status' => $statusFilter, 'page' => $number])) ?>"><?= escape($label) ?></a><?php endforeach; ?></div>
    <?php elseif ($screen === 'review' && $application): $pathway = pathwayConfig($period, $application['pathway']); ?>
        <p class="lead"><?= escape($data['name'] . ' · ' . $period['school']) ?></p><p><?= escape($application['registration_number']) ?> · <?= escape($pathway['name']) ?></p>
        <?php if (in_array($user['role'], ['central_admin','school_admin'], true)): ?>
        <form method="post" class="form-card"><h2>Penugasan verifikator</h2>
            <input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="assign">
            <input type="hidden" name="assignment_version" value="<?= (int) ($assignment['version'] ?? 0) ?>">
            <div class="field"><label for="reviewer_id">Verifikator sekolah peserta</label><select name="reviewer_id" id="reviewer_id"><option value="0">Belum ditugaskan / lepaskan penugasan</option><?php foreach ($reviewers as $reviewer): ?><option value="<?= (int) $reviewer['id'] ?>"<?= (int) ($assignment['reviewer_id'] ?? 0) === (int) $reviewer['id'] ? ' selected' : '' ?>><?= escape($reviewer['name']) ?></option><?php endforeach; ?></select></div>
            <button class="button button-primary compact-button">Simpan penugasan</button><p class="field-help">Admin pusat/sekolah tetap dapat memeriksa. Verifikator hanya dapat mengakses peserta yang ditugaskan kepadanya.</p>
        </form>
        <?php else: ?><p>Ditugaskan kepada: <?= escape($assignment['reviewer_name'] ?? $user['name']) ?></p><?php endif; ?>
        <section class="form-card"><h2>Data peserta yang dikirim</h2><?php participantSummary($data); ?><p>Akun wali: <?= escape($application['guardian_account'] . ' · ' . $application['guardian_email']) ?></p></section>
        <section class="form-card"><h2>Dokumen persyaratan</h2><p class="field-help">Periksa isi dan keaslian dokumen. Checklist unggahan tidak berarti dokumen sudah valid.</p>
            <?php foreach ($pathway['documents'] as $requirement): $document = $documents[$requirement['code']] ?? null; ?>
                <article class="document-card"><h3><?= escape($requirement['label']) ?> · <?= $requirement['required'] ? 'Wajib' : 'Opsional' ?></h3>
                    <?php if ($document): ?><p><?= escape($document['original_name']) ?></p><?php if ($document['mime_type'] !== 'application/pdf'): ?><img class="document-preview" src="/documents/<?= escape($document['id']) ?>" alt="<?= escape($requirement['label']) ?>" loading="lazy"><?php endif; ?>
                        <a class="text-link" href="/documents/<?= escape($document['id']) ?>?download=1">Unduh dokumen</a>
                    <?php else: ?><p>Belum diunggah.</p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
        <form method="post" class="form-card"><h2>Keputusan verifikasi</h2><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="verification_version" value="<?= (int) ($verification['version'] ?? 0) ?>">
            <p>Status saat ini: <strong><?= escape(verificationLabel($verification['status'] ?? null)) ?></strong></p>
            <div class="field"><label for="decision">Hasil pemeriksaan</label><select id="decision" name="decision" required><option value="">Pilih keputusan</option><?php foreach (['valid', 'needs_correction', 'invalid'] as $state): ?><option value="<?= escape($state) ?>"<?= $decision === $state ? ' selected' : '' ?>><?= escape(verificationLabel($state)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="note">Catatan untuk wali</label><textarea id="note" name="note" rows="4" minlength="5" maxlength="2000" required><?= escape($note) ?></textarea></div>
            <p class="field-help">Catatan wajib dan terlihat oleh wali. Perlu perbaikan tidak membuka kunci data; wali perlu menghubungi panitia. Keputusan ini bukan hasil seleksi.</p>
            <div class="form-actions"><a class="button button-outline" href="/admin/applications">Kembali</a><button class="button button-primary compact-button" type="submit">Simpan verifikasi</button></div>
        </form>
        <section class="form-card"><h2>Riwayat verifikasi</h2><p class="field-help">Maksimal 50 keputusan terakhir. Keputusan sebelumnya tidak ditimpa dari riwayat.</p>
            <ol class="activity-list"><?php foreach ($history as $event): ?><li><strong><?= escape(verificationLabel($event['status'])) ?></strong><p><?= nl2br(escape($event['note'])) ?></p><span><?= escape($event['reviewer_name'] . ' · ' . admissionDate((int) $event['created_at'], $period['timezone'])) ?></span></li><?php endforeach; ?></ol>
        </section>
    <?php elseif ($screen === 'master'): require __DIR__ . '/master.php'; ?>
    <?php elseif ($screen === 'accounts'): require __DIR__ . '/staff_accounts.php'; ?>
    <?php elseif ($screen === 'audit'): ?>
        <section class="form-card"><h2>100 aktivitas terakhir</h2><ol class="activity-list"><?php foreach ($rows as $row): ?><li><strong><?= escape($row['action']) ?></strong><span><?= escape(($row['name'] ?? 'Tanpa akun') . ' · ' . admissionDate((int) $row['created_at'], 'Asia/Jakarta')) ?></span></li><?php endforeach; ?></ol></section>
    <?php else: ?><a class="button button-outline" href="<?= isStaff($user) ? '/admin' : '/dashboard' ?>">Kembali</a><?php endif; ?>
</main><footer class="dashboard-footer">PPDB · Portal panitia</footer>
</body></html>
