<?php
declare(strict_types=1);
require_once __DIR__ . '/master_icon.php';
function operationalFormValue(mixed $value): string
{
    return is_string($value) || is_int($value) ? (string) $value : '';
}
function operationalNested(mixed $values, string ...$keys): mixed
{
    foreach ($keys as $key) {
        if (!is_array($values)) {
            return '';
        }
        $values = $values[$key] ?? '';
    }
    return $values;
}
function operationalField(string $name, string $label, mixed $value, string $kind = 'text', int $max = 200, bool $readonly = false): void
{
    ?>
    <div class="field"><label><?= escape($label) ?>
    <?php if ($kind === 'textarea'): ?><textarea name="<?= escape($name) ?>" maxlength="<?= $max ?>" rows="3" required><?= escape(operationalFormValue($value)) ?></textarea>
    <?php else: ?><input type="<?= escape($kind) ?>" name="<?= escape($name) ?>" value="<?= escape(operationalFormValue($value)) ?>"<?= $kind === 'number' ? ' min="0" max="100000" step="1"' : ' maxlength="' . $max . '"' ?><?= $readonly ? ' readonly' : '' ?> required><?php endif; ?>
    </label></div>
    <?php
}
?>
<?php if ($type === 'years'): ?>
<p class="lead">Lengkapi rentang tanggal tahun ajaran sebelum menyusun paket aturan.</p>
<?php else: ?>
<p class="lead">Paket memuat kapasitas rombel, kuota kursi, jadwal dan Juknis. Admin pusat menyusun; approver sekolah yang berbeda meninjau. Penerbitan tidak membuka produksi dan tidak mengaktifkan katalog otomatis.</p>
<?php endif; ?>
<?php if ($mode === 'list'): ?>
<div class="section-heading"><h2><?= $type === 'years' ? 'Daftar tahun ajaran' : 'Daftar versi aturan' ?></h2>
<?php if (!$approverPortal): ?><a class="button button-primary compact-button" href="<?= escape($base) ?>/new">Tambah <?= $type === 'years' ? 'tahun ajaran' : 'paket aturan' ?></a><?php endif; ?></div>
<form method="get" class="form-card"><div class="form-grid">
<div class="field"><label for="q"><?= $type === 'years' ? 'Cari tahun ajaran' : 'Cari sekolah / kode periode / tahun' ?></label><input id="q" name="q" value="<?= escape($query) ?>" maxlength="200"></div>
<?php if ($type === 'rules'): ?><div class="field"><label for="status">Status paket</label><select name="status" id="status"><option value="">Semua status</option><?php foreach (['draft','pending','returned','approved','published','superseded'] as $status): ?><option value="<?= $status ?>"<?= $filter === $status ? ' selected' : '' ?>><?= escape(operationalStatus($status)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
</div><div class="action-row"><button class="button button-primary compact-button">Cari</button><a class="button button-outline" href="<?= escape($base) ?>">Reset</a></div></form>
<div class="master-table-wrap" role="region" aria-label="Tabel master operasional" tabindex="0"><table class="master-table"><caption><?= $total ?> data ditemukan</caption>
<thead><tr><?php if ($type === 'years'): ?><th>Tahun ajaran</th><th>Rentang</th><th>Periode / paket</th><th>Status</th><?php else: ?><th>Sekolah / periode</th><th>Tahun ajaran</th><th>Versi / kapasitas</th><th>Status</th><?php endif; ?><th>Aksi</th></tr></thead>
<tbody><?php foreach ($rows as $row): ?><tr>
<?php if ($type === 'years'): ?><td><?= escape($row['label']) ?></td><td><?= escape($row['starts_on'] ?: 'Belum dilengkapi') ?><small><?= escape($row['ends_on']) ?></small></td><td><?= (int) $row['periods'] ?> periode / <?= (int) $row['packs'] ?> paket</td><td>
<form class="master-status-form" method="post" action="<?= escape($base . '/' . $row['id'] . '?' . http_build_query(['q'=>$query,'page'=>$page,'return_list'=>'1'])) ?>"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="version" value="<?= (int) $row['version'] ?>"><input type="hidden" name="action" value="<?= (int) $row['enabled'] ? 'archive' : 'activate' ?>">
<button class="master-status-switch" role="switch" aria-checked="<?= (int) $row['enabled'] ? 'true' : 'false' ?>" aria-label="<?= escape(((int) $row['enabled'] ? 'Arsipkan ' : 'Aktifkan ') . $row['label']) ?>"><span aria-hidden="true"></span></button></form></td>
<?php else: $payload = admissionData($row['payload_json']); ?><td><?= escape(admissionDisplayText($row['school'])) ?><small><?= escape($row['code']) ?></small></td><td><?= escape($row['academic_year']) ?></td><td>Versi <?= (int) $row['revision'] ?><small><?= (int) $payload['capacity'] ?> kursi / <?= count($payload['classes']) ?> rombel</small></td><td><span class="status-tag"><?= escape(operationalStatus($row['status'])) ?></span></td><?php endif; ?>
<td><div class="master-row-actions"><a class="master-icon-action" aria-label="Detail" title="Detail" href="<?= escape($base . '/' . $row['id']) ?>"><?php masterIcon('detail'); ?></a>
<?php if (!$approverPortal && ($type === 'years' || in_array($row['status'], ['draft','returned'], true))): ?><a class="master-icon-action" aria-label="Edit" title="Edit" href="<?= escape($base . '/' . $row['id'] . '/edit') ?>"><?php masterIcon('edit'); ?></a><?php endif; ?>
<?php if ($type === 'years' && !(int) $row['periods'] && !(int) $row['packs']): ?><a class="master-icon-action danger-icon" aria-label="Hapus" title="Hapus" href="<?= escape($base . '/' . $row['id'] . '/delete') ?>"><?php masterIcon('delete'); ?></a><?php endif; ?>
</div></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="5">Belum ada data yang cocok.</td></tr><?php endif; ?></tbody></table></div>
<nav class="master-pagination" aria-label="Pagination operasional"><span>Halaman <?= $page ?> / <?= max(1, (int) ceil($total / 10)) ?></span><div class="action-row">
<?php foreach ([$page - 1 => 'Sebelumnya', $page + 1 => 'Berikutnya'] as $number => $text): if ($number < 1 || ($number > $page && $page * 10 >= $total)) continue; ?><a class="button button-outline" href="<?= escape($base . '?' . http_build_query(['q'=>$query,'status'=>$filter,'page'=>$number])) ?>"><?= escape($text) ?></a><?php endforeach; ?></div></nav>
<?php elseif ($mode === 'choose-period'): ?>
<form method="get" class="form-card"><div class="field"><label for="period">Pilih periode sumber</label><select id="period" name="period" required><option value="">Pilih sekolah / periode</option><?php foreach ($periods as $period): ?><option value="<?= escape($period['id']) ?>"><?= escape(admissionDisplayText($period['school']) . ' · ' . $period['academic_year'] . ' · ' . $period['code']) ?></option><?php endforeach; ?></select></div><button class="button button-primary compact-button">Lanjut menyusun paket</button></form>
<?php elseif ($mode === 'edit' && $type === 'years'): ?>
<form method="post" class="form-card"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><?php if ($item): ?><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><?php endif; ?>
<div class="form-grid"><?php operationalField('label','Tahun ajaran (2026/2027)', $values['label'] ?? '', 'text',9); operationalField('starts_on','Tanggal mulai', $values['starts_on'] ?? '', 'date'); operationalField('ends_on','Tanggal akhir', $values['ends_on'] ?? '', 'date'); ?></div>
<div class="action-row"><button class="button button-primary compact-button">Simpan tahun ajaran</button><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a></div></form>
<?php elseif ($mode === 'edit' && $source): ?>
<form method="post" class="form-card" data-draft-form data-operational-editor>
<h2><?= escape(admissionDisplayText($source['configuration']['school']) . ' · ' . $source['configuration']['code']) ?></h2>
<p class="field-help">Versi kerja dapat diedit sebelum diajukan. Membuat paket pertama mengarsipkan periode untuk menunggu persetujuan. Data lama tetap dapat dibaca. Periode yang pernah dipakai tidak boleh diterbitkan ulang; gunakan periode baru.</p>
<input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="period_id" value="<?= escape($source['period_id']) ?>">
<?php if ($item): ?><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><?php endif; ?>
<div class="form-grid"><div class="field"><label for="year_id">Tahun ajaran</label><select name="year_id" id="year_id" required><option value="">Pilih tahun ajaran</option><?php foreach ($years as $year): ?><option value="<?= escape($year['id']) ?>"<?= ($values['year_id'] ?? '') === $year['id'] ? ' selected' : '' ?><?= !(int) $year['enabled'] ? ' disabled' : '' ?>><?= escape($year['label'] . (!$year['starts_on'] ? ' · tanggal belum lengkap' : '')) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="timezone">Zona waktu periode</label><select name="timezone" id="timezone"><option><?= escape($source['configuration']['timezone']) ?></option></select></div>
<?php operationalField('capacity','Daya tampung total (kursi)', $values['capacity'] ?? '', 'number'); operationalField('class_limit','Batas kursi per rombel sesuai Juknis', $values['class_limit'] ?? '', 'number'); ?></div>
<h3>Rombel penerimaan</h3><p class="field-help">Total kursi harus sama dengan daya tampung. Tidak ada batas rombel yang diasumsikan resmi; isi dari Juknis.</p>
<div data-operational-classes><?php foreach (is_array($values['classes'] ?? null) ? $values['classes'] : [] as $i => $class): if (!is_array($class)) continue; ?>
<div class="operational-class-row" data-operational-class><?php operationalField('classes[' . $i . '][name]', 'Nama rombel', $class['name'] ?? '', 'text',100); operationalField('classes[' . $i . '][seats]', 'Kursi', $class['seats'] ?? '', 'number'); ?><button type="submit" name="remove_class" value="<?= escape((string) $i) ?>" formnovalidate class="button button-outline button-danger" data-remove-operational-class>Hapus rombel</button></div><?php endforeach; ?></div>
<button type="submit" name="action" value="add-class" formnovalidate class="button button-outline" data-add-operational-class>Tambah rombel</button><p role="status" aria-live="polite" data-operational-status></p>
<h3>Kuota jalur (jumlah kursi)</h3><p class="field-help">Isi kursi bulat; total harus tepat kapasitas. Persentase dihitung dari kursi, bukan dibulatkan untuk validasi. Pembagian/sisa kursi tidak diisi otomatis.</p><div class="form-grid">
<?php foreach ($source['configuration']['pathways'] as $route): operationalField('quotas[' . $route['code'] . ']', 'Kuota ' . $route['name'], $values['quotas'][$route['code']] ?? '', 'number'); endforeach; ?></div>
<h3>Jadwal tahap</h3><p class="field-help">Format YYYY-MM-DD HH:MM:SS. Pendaftaran mengikuti jadwal periode. Verifikasi boleh overlap pendaftaran/perbaikan; seleksi setelah ketiganya selesai. Pengumuman, sanggah, daftar ulang berurutan. Jadwal tahap selain pendaftaran belum mengeksekusi modul seleksi/koreksi otomatis.</p>
<?php foreach (operationalStages() as $code => $label): ?><fieldset class="pathway-editor-row"><legend><?= escape($label) ?></legend><div class="form-grid">
<?php operationalField('schedule[' . $code . '][start]', 'Mulai ' . strtolower($label), operationalNested($values, 'schedule', $code, 'start'), 'text',19, $code === 'registration'); operationalField('schedule[' . $code . '][end]', 'Selesai ' . strtolower($label), operationalNested($values, 'schedule', $code, 'end'), 'text',19, $code === 'registration'); ?></div></fieldset><?php endforeach; ?>
<h3>Juknis dan dasar aturan</h3><p class="field-help">Masukkan rujukan resmi sesuai wilayah/tahun; tautan tidak diunduh server. Pengesahan di aplikasi adalah review panitia, bukan jaminan legalitas naskah.</p><div class="form-grid">
<?php foreach (['number'=>'Nomor Juknis','issuer'=>'Penerbit','version'=>'Versi naskah','url'=>'Tautan HTTPS naskah'] as $key => $label): operationalField('juknis[' . $key . ']', $label, $values['juknis'][$key] ?? '', $key === 'url' ? 'url' : 'text',500); endforeach; ?>
<?php foreach (['date'=>'Tanggal terbit','effective_from'=>'Mulai berlaku','effective_until'=>'Berlaku sampai'] as $key => $label): operationalField('juknis[' . $key . ']', $label, $values['juknis'][$key] ?? '', 'date'); endforeach; ?></div>
<?php operationalField('juknis[notes]', 'Ketentuan prioritas, tie-break, pembulatan dan kursi sisa (rujukan pasal)', $values['juknis']['notes'] ?? '', 'textarea',4000); operationalField('reason','Alasan pembuatan / perubahan versi', $values['reason'] ?? '', 'textarea',2000); ?>
<div class="action-row"><button class="button button-primary compact-button">Simpan draf paket</button><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a></div>
<template data-operational-class-template><div class="operational-class-row" data-operational-class><div class="field"><label>Nama rombel<input name="classes[0][name]" maxlength="100" required></label></div><div class="field"><label>Kursi<input type="number" name="classes[0][seats]" min="1" max="1000" step="1" required></label></div><button type="submit" name="remove_class" value="0" formnovalidate class="button button-outline button-danger" data-remove-operational-class>Hapus rombel</button></div></template>
</form>
<?php elseif ($item && $type === 'years'): ?>
<section class="form-card"><h2><?= escape($item['label']) ?></h2><p><?= escape(($item['starts_on'] ?: 'Tanggal belum lengkap') . ' — ' . $item['ends_on']) ?></p><p>Status: <?= (int) $item['enabled'] ? 'Aktif' : 'Diarsipkan' ?></p>
<?php if ($mode === 'delete'): ?><form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><label class="checkbox-field"><input type="checkbox" name="confirm_delete" value="1" required> Hapus permanen tahun ajaran yang belum dipakai.</label><button class="button button-outline button-danger">Hapus tahun ajaran</button></form><?php else: ?><div class="action-row"><a class="button button-outline" href="<?= escape($base . '/' . $item['id'] . '/edit') ?>">Edit</a><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a></div><?php endif; ?></section>
<?php elseif ($item && $source): ?>
<section class="form-card"><h2><?= escape(admissionDisplayText($item['school']) . ' · ' . $item['code']) ?></h2><p><span class="status-tag"><?= escape(operationalStatus($item['status'])) ?></span> · Versi <?= (int) $item['revision'] ?> · <?= escape($item['academic_year']) ?></p>
<p>Alasan: <?= nl2br(escape($item['reason'])) ?></p><p>Fingerprint persetujuan: <code class="operational-hash"><?= escape($item['payload_hash'] ?? 'Belum diajukan') ?></code></p>
<dl class="summary-grid"><div><dt>Daya tampung</dt><dd><?= (int) $values['capacity'] ?> kursi</dd></div><div><dt>Rombel</dt><dd><?= count($values['classes']) ?> · batas <?= (int) $values['class_limit'] ?> kursi per rombel</dd></div><div><dt>Tahun ajaran</dt><dd><?= escape($values['year']['label'] . ' · ' . $values['year']['starts_on'] . ' — ' . $values['year']['ends_on']) ?></dd></div><div><dt>Zona waktu</dt><dd><?= escape($values['timezone']) ?></dd></div></dl>
<h3>Rombel</h3><ul><?php foreach ($values['classes'] as $class): ?><li><?= escape($class['name']) ?>: <?= (int) $class['seats'] ?> kursi</li><?php endforeach; ?></ul>
<h3>Kuota jalur</h3><ul><?php foreach ($source['configuration']['pathways'] as $route): ?><li><?= escape($route['name']) ?>: <?= (int) $values['quotas'][$route['code']] ?> kursi (<?= escape(number_format($values['quotas'][$route['code']] * 100 / $values['capacity'], 2, ',', '.')) ?>%)</li><?php endforeach; ?></ul>
<h3>Jadwal tahap</h3><div class="master-table-wrap"><table class="master-table"><thead><tr><th>Tahap</th><th>Mulai</th><th>Selesai</th></tr></thead><tbody><?php foreach (operationalStages() as $code => $label): ?><tr><td><?= escape($label) ?></td><td><?= escape($values['schedule'][$code]['start']) ?></td><td><?= escape($values['schedule'][$code]['end']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<h3>Juknis</h3><p><?= escape($values['juknis']['number'] . ' · ' . $values['juknis']['issuer'] . ' · versi ' . $values['juknis']['version']) ?></p><p><?= escape($values['juknis']['date'] . ' · berlaku ' . $values['juknis']['effective_from'] . ' — ' . $values['juknis']['effective_until']) ?></p><p><a href="<?= escape($values['juknis']['url']) ?>" target="_blank" rel="noopener noreferrer">Lihat naskah Juknis</a></p><p><?= nl2br(escape($values['juknis']['notes'])) ?></p>
<h3>Jalur dan persyaratan yang ditinjau</h3><?php foreach ($source['configuration']['pathways'] as $route): ?><h4><?= escape($route['name']) ?></h4><p><?= escape($route['description']) ?></p><ul><?php foreach ($route['documents'] as $doc): ?><li><?= escape(admissionDisplayText($doc['label'])) ?> · <?= $doc['required'] ? 'Wajib' : 'Opsional' ?></li><?php endforeach; ?></ul><?php endforeach; ?>
<p><?= escape($source['configuration']['rule_reference']) ?></p><p><?= escape($source['configuration']['privacy_notice']) ?></p><p>Kontak: <?= escape($source['configuration']['help_contact']) ?></p>
<div class="action-row"><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a>
<?php if (!$approverPortal && in_array($item['status'], ['draft','returned'], true)): ?><a class="button button-outline" href="<?= escape($base . '/' . $id . '/edit') ?>">Edit draf</a><?php endif; ?>
<?php if (!$approverPortal && in_array($item['status'], ['published','superseded'], true)): ?><a class="button button-outline" href="<?= escape($base . '/new?copy=' . $id) ?>">Buat versi baru</a><?php endif; ?>
</div>
<?php $actions = [];
if (!$approverPortal && in_array($item['status'], ['draft','returned'], true)) $actions['submit'] = 'Ajukan persetujuan';
if ($item['status'] === 'pending' && canReviewOperational($db, $user, $item['school_id']) && (int) $user['id'] !== (int) $item['created_by'] && (int) $user['id'] !== (int) $item['submitted_by']) $actions = ['approve'=>'Setujui paket','return'=>'Kembalikan untuk perbaikan'];
if (!$approverPortal && $item['status'] === 'approved') $actions = ['publish'=>'Terbitkan paket', 'resubmit'=>'Ajukan ulang ke approver'];
?>
<?php if ($actions): ?><form method="post" class="operational-decision"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="version" value="<?= (int) $item['version'] ?>"><?php operationalField('note','Catatan keputusan (wajib)', '', 'textarea',2000); ?><div class="action-row"><?php foreach ($actions as $action => $text): ?><button class="button <?= $action === 'return' ? 'button-outline' : 'button-primary compact-button' ?>" name="action" value="<?= $action ?>"><?= escape($text) ?></button><?php endforeach; ?></div></form><?php endif; ?>
</section>
<section class="form-card"><h2>Riwayat paket</h2><ol class="activity-list"><?php foreach ($history as $event): ?><li><strong><?= escape($event['action'] . ' · ' . $event['actor_name']) ?></strong><p><?= nl2br(escape($event['note'])) ?></p><span><?= escape(admissionDate((int) $event['created_at'], $values['timezone'])) ?></span></li><?php endforeach; ?></ol></section>
<?php endif; ?>
