<?php
declare(strict_types=1);
require_once __DIR__ . '/master_icon.php';
$available = $filters['queue'] === 'available';
$page = (int) $filters['page'];
$queueLabels = [''=>'Semua tugas','unassigned'=>'Belum ditugaskan','assigned'=>'Sedang ditangani','overdue'=>'Terlambat','completed'=>'Sudah diputuskan'];
if ($user['role'] === 'verifier') {
    unset($queueLabels['unassigned']);
    $queueLabels['available'] = 'Tugas kosong sekolah';
}
$years = array_unique(array_column($periods, 'academic_year'));
rsort($years);
$pathways = [];
foreach ($periods as $periodOption) {
    foreach (admissionData($periodOption['config_json'])['pathways'] as $route) {
        $pathways[$route['code']] = $route['name'];
    }
}
?>
<p class="lead">Kelola pendaftaran terkirim dan antrean verifikasi. Draf tidak ditampilkan. Sudah diputuskan berarti keputusan verifikasi tercatat, bukan diterima seleksi.</p>
<?php if ($user['role'] === 'verifier'): ?><div class="action-row"><a class="button button-outline" href="/admin/queue?queue=assigned">Tugas saya</a><a class="button button-outline" href="/admin/queue?queue=available">Ambil tugas kosong</a></div><?php endif; ?>
<?php if ($available): ?><p class="field-help">Hanya nomor pendaftaran, sekolah/jalur dan waktu ditampilkan. Identitas, duplikasi, detail dan dokumen baru terbuka setelah tugas berhasil diambil. Dua petugas tidak bisa mengambil tugas yang sama.</p><?php endif; ?>
<form method="get" action="/admin/applications" class="form-card">
<div class="form-grid">
<?php if (!$available): ?><div class="field"><label for="q">Cari nama peserta / nomor pendaftaran</label><input id="q" name="q" maxlength="200" value="<?= escape($filters['q']) ?>"></div><?php endif; ?>
<div class="field"><label for="period">Sekolah / periode</label><select id="period" name="period"><option value="">Semua periode dalam akses</option><?php foreach ($periods as $option): ?><option value="<?= escape($option['id']) ?>"<?= $filters['period'] === $option['id'] ? ' selected' : '' ?>><?= escape(admissionDisplayText($option['school']) . ' · ' . $option['code']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="year">Tahun ajaran</label><select id="year" name="year"><option value="">Semua tahun</option><?php foreach ($years as $year): ?><option<?= $filters['year'] === $year ? ' selected' : '' ?>><?= escape($year) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="pathway">Jalur</label><select id="pathway" name="pathway"><option value="">Semua jalur</option><?php foreach ($pathways as $code=>$label): ?><option value="<?= escape($code) ?>"<?= $filters['pathway'] === $code ? ' selected' : '' ?>><?= escape($label) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="queue">Antrean kerja</label><select id="queue" name="queue"><?php foreach ($queueLabels as $code=>$label): ?><option value="<?= escape($code) ?>"<?= $filters['queue'] === $code ? ' selected' : '' ?>><?= escape($label) ?></option><?php endforeach; ?></select></div>
<?php if (!$available): ?>
<div class="field"><label for="status">Status verifikasi</label><select id="status" name="status"><option value="">Semua status</option><?php foreach (['pending','valid','needs_correction','invalid'] as $status): ?><option value="<?= $status ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= escape(verificationLabel($status)) ?></option><?php endforeach; ?></select></div>
<?php if ($user['role'] !== 'verifier'): ?><div class="field"><label for="reviewer">Verifikator</label><select id="reviewer" name="reviewer"><option value="">Semua verifikator</option><?php foreach ($queueReviewers as $reviewer): ?><option value="<?= (int) $reviewer['id'] ?>"<?= $filters['reviewer'] === (string) $reviewer['id'] ? ' selected' : '' ?>><?= escape($reviewer['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<div class="field"><label for="duplicate">Potensi duplikasi</label><select id="duplicate" name="duplicate"><option value="">Semua peserta</option><option value="potential"<?= $filters['duplicate'] === 'potential' ? ' selected' : '' ?>>Perlu tinjauan duplikasi</option></select></div>
<?php endif; ?>
<div class="field"><label for="sort">Urutan</label><select id="sort" name="sort"><?php foreach (['oldest'=>'Kiriman terlama','newest'=>'Kiriman terbaru','name'=>'Nama peserta','number'=>'Nomor pendaftaran','deadline'=>'Tenggat terdekat'] as $code=>$label): if ($available && $code === 'name') continue; ?><option value="<?= $code ?>"<?= $filters['sort'] === $code ? ' selected' : '' ?>><?= escape($label) ?></option><?php endforeach; ?></select></div>
</div><div class="action-row"><button class="button button-primary compact-button">Terapkan filter</button><a class="button button-outline" href="<?= $available ? '/admin/queue?queue=available' : '/admin/applications' ?>">Reset</a></div></form>
<div class="stats-grid admin-stats">
<?php foreach (['total'=>'Kiriman dalam filter','unassigned'=>'Belum ditugaskan','assigned'=>'Sedang ditangani','overdue'=>'Terlambat','completed'=>'Sudah diputuskan','duplicates'=>'Potensi duplikasi'] as $key=>$label): if ($available && in_array($key,['assigned','completed','duplicates'],true)) continue; ?><div><span><?= escape($label) ?></span><strong><?= (int) $summary[$key] ?></strong></div><?php endforeach; ?>
</div>
<p class="field-help">Ringkasan mengikuti filter selain pilihan antrean dan pagination. Terlambat: belum ada keputusan setelah akhir verifikasi dari snapshot paket yang dikirim peserta. Tanpa paket: tenggat belum diatur. Potensi duplikasi hanya dibandingkan sesama kiriman yang boleh Anda periksa pada tahun ajaran sama (NISN, atau nama + tanggal lahir); tidak otomatis ditolak.</p>
<div class="master-table-wrap" role="region" aria-label="Tabel pendaftar dan antrean" tabindex="0">
<table class="master-table"><caption><?= $total ?> pendaftaran ditemukan</caption><thead><tr><th><?= $available ? 'Nomor pendaftaran' : 'Peserta / nomor' ?></th><th>Sekolah / jalur</th><th>Waktu / tenggat</th><th>Status / penugasan</th><?php if (!$available): ?><th>Tinjauan</th><?php endif; ?><th>Aksi</th></tr></thead>
<tbody><?php foreach ($rows as $row): ?><tr>
<td><?php if (!$available): ?><?= escape($row['student_name']) ?><small><?php endif; ?><?= escape($row['registration_number']) ?><?php if (!$available): ?></small><?php endif; ?></td>
<td><?= escape(admissionDisplayText($row['school'])) ?><small><?= escape($row['code'] . ' · ' . $row['academic_year'] . ' · ' . $row['pathway']) ?></small></td>
<td><?= escape(admissionDate((int) $row['submitted_at'], $row['timezone'])) ?><small><?= $row['deadline'] === null ? 'Tenggat belum diatur' : 'Verifikasi: ' . escape(admissionDate((int) $row['deadline'], $row['timezone'])) ?></small></td>
<td><span class="status-tag"><?= escape(verificationLabel($row['verification_status'])) ?></span><small>Revisi <?= (int)$row['revision'] ?> · <?= escape($row['reviewer_name'] ?? 'Belum ditugaskan') ?></small><?php if ($row['verification_status'] === null && $row['deadline'] !== null && (int) $row['deadline'] < time()): ?><small>Terlambat</small><?php endif; ?></td>
<?php if (!$available): ?><td><?= (int) $row['potential_duplicate'] ? 'Potensi duplikasi — tinjau manual' : '—' ?></td><?php endif; ?>
<td><?php if ($available): ?><form method="post" action="/admin/queue/<?= escape($row['id']) ?>/claim"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="assignment_version" value="<?= (int) $row['assignment_version'] ?>"><button class="button button-primary compact-button">Ambil tugas</button></form><?php else: ?><a class="master-icon-action" title="Periksa peserta" aria-label="Periksa peserta" href="/admin/applications/<?= escape($row['id']) ?>"><?php masterIcon('detail'); ?></a><?php endif; ?></td>
</tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="<?= $available ? 5 : 6 ?>">Tidak ada pendaftaran terkirim yang cocok.</td></tr><?php endif; ?></tbody></table></div>
<nav class="master-pagination" aria-label="Pagination pendaftar"><span>Halaman <?= $page ?> / <?= max(1,(int)ceil($total/25)) ?> · 25 baris per halaman</span><div class="action-row"><?php foreach ([$page-1=>'Sebelumnya',$page+1=>'Berikutnya'] as $number=>$label): if ($number<1 || ($number>$page && $page*25 >= $total)) continue; ?><a class="button button-outline" href="/admin/applications?<?= escape(http_build_query(array_replace($filters,['page'=>(string)$number]))) ?>"><?= escape($label) ?></a><?php endforeach; ?></div></nav>
