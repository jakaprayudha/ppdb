<?php
declare(strict_types=1);
require_once __DIR__ . '/master_icon.php';

function masterInput(string $key, string $label, array $values, int $maximum = 200, bool $textarea = false): void
{
    ?>
    <div class="field<?= $textarea ? ' field-wide' : '' ?>"><label for="<?= escape($key) ?>"><?= escape($label) ?></label>
        <?php if ($textarea): ?><textarea id="<?= escape($key) ?>" name="<?= escape($key) ?>" rows="3" maxlength="<?= $maximum ?>" required><?= escape($values[$key] ?? '') ?></textarea>
        <?php else: ?><input id="<?= escape($key) ?>" name="<?= escape($key) ?>" maxlength="<?= $maximum ?>" value="<?= escape($values[$key] ?? '') ?>" required><?php endif; ?>
    </div>
    <?php
}

function masterStatusSwitch(array $record, string $type, string $url): void
{
    $enabled = (int) $record['enabled'] === 1;
    $name = admissionDisplayText($type === 'schools' ? $record['name'] : $record['school']);
    $label = ($enabled ? 'Arsipkan ' : 'Aktifkan ') . $name;
    ?>
    <form method="post" action="<?= escape($url) ?>" class="master-status-form">
        <input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>">
        <input type="hidden" name="version" value="<?= (int) $record[$type === 'schools' ? 'version' : 'management_version'] ?>">
        <input type="hidden" name="action" value="<?= $enabled ? 'archive' : 'activate' ?>">
        <button type="submit" class="master-status-switch" role="switch" aria-checked="<?= $enabled ? 'true' : 'false' ?>" aria-label="<?= escape($label) ?>" title="<?= escape($label . ($type === 'schools' && $enabled ? ' beserta seluruh periodenya' : '')) ?>"><span aria-hidden="true"></span></button>
    </form>
    <?php
}

$base = '/admin/master-data/' . $type;
$schoolType = $type === 'schools';
$label = $schoolType ? 'Sekolah' : 'Periode pendaftaran';
?>
<?php if ($mode === 'list'): ?>
    <div class="section-heading"><h2><?= escape($label) ?></h2><a class="button button-primary compact-button" href="<?= escape($base) ?>/new">Tambah <?= escape(strtolower($label)) ?></a></div>
    <p class="lead">Data belum dipakai boleh diedit/dihapus. Setelah ada pendaftaran, gunakan arsip atau buat periode baru. Arsip tidak menghapus data peserta.</p>
    <form method="get" class="form-card"><div class="field"><label for="q">Cari <?= $schoolType ? 'nama sekolah / NPSN / kecamatan' : 'sekolah / kode periode / tahun ajaran' ?></label><input id="q" name="q" value="<?= escape($query) ?>"></div>
        <div class="action-row"><button class="button button-primary compact-button">Cari</button><a class="text-link" href="<?= escape($base) ?>">Reset</a></div></form>
    <div class="master-table-wrap" role="region" aria-label="Tabel <?= escape(strtolower($label)) ?>" tabindex="0">
        <table class="master-table"><caption><?= escape($label) ?> · <?= $total ?> data ditemukan</caption>
            <thead><tr><th scope="col">No.</th><?php if ($schoolType): ?><th scope="col">Sekolah / NPSN</th><th scope="col">Jenjang / Mode</th><th scope="col">Wilayah</th><th scope="col">Periode</th>
                <?php else: ?><th scope="col">Sekolah / Kode periode</th><th scope="col">Tahun ajaran</th><th scope="col">Jadwal</th><th scope="col">Jalur</th><?php endif; ?><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
            <tbody><?php foreach ($rows as $index => $row): ?>
                <tr><td><?= ($page - 1) * 10 + $index + 1 ?></td>
                    <?php if ($schoolType): ?><td><strong><?= escape(admissionDisplayText($row['name'])) ?></strong><small>NPSN <?= escape($row['npsn'] ?? 'belum tersedia') ?></small></td><td><?= escape($row['level']) ?><small><?= $row['mode'] === 'public_spmb' ? 'Negeri' : 'Swasta' ?></small></td><td><?= escape($row['district'] ?: 'Belum diisi') ?><small><?= escape($row['city']) ?></small></td><td><?= (int) $row['periods'] ?></td>
                    <?php else: $rules = admissionData($row['config_json']); ?><td><strong><?= escape(admissionDisplayText($row['school'])) ?></strong><small><?= escape($row['code']) ?></small></td><td><?= escape($row['academic_year']) ?></td><td><?= escape(admissionDate((int) $row['opens_at'], $row['timezone'])) ?><small>s/d <?= escape(admissionDate((int) $row['closes_at'], $row['timezone'])) ?></small></td><td><?= escape(implode(', ', array_column($rules['pathways'], 'name'))) ?></td><?php endif; ?>
                    <td><?php masterStatusSwitch($row, $type, $base . '/' . $row['id'] . '?' . http_build_query(['q' => $query, 'page' => $page, 'return_list' => '1'])); ?><small><?= (int) $row['used'] ? 'Sudah dipakai' : 'Belum dipakai' ?></small></td>
                    <td><div class="master-row-actions"><a class="master-icon-action" href="<?= escape($base . '/' . $row['id']) ?>" aria-label="Detail" title="Detail"><?php masterIcon('detail'); ?></a>
                        <?php if (!(int) $row['used']): ?><a class="master-icon-action" href="<?= escape($base . '/' . $row['id']) ?>/edit" aria-label="Edit" title="Edit"><?php masterIcon('edit'); ?></a><?php endif; ?>
                        <?php if (!(int) $row['used'] && (!$schoolType || !(int) $row['periods'])): ?><a class="master-icon-action danger-link" href="<?= escape($base . '/' . $row['id']) ?>/delete" aria-label="Hapus" title="Hapus"><?php masterIcon('delete'); ?></a><?php endif; ?>
                        <?php if (!$schoolType): ?><a class="master-icon-action" href="<?= escape($base . '/new?copy=' . $row['id']) ?>" aria-label="Salin baru" title="Salin baru"><?php masterIcon('copy'); ?></a><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="7">Tidak ada data yang cocok.</td></tr><?php endif; ?></tbody>
        </table>
    </div>
    <nav class="master-pagination" aria-label="Pagination master data">
        <span><?= $rows ? (($page - 1) * 10 + 1) : 0 ?>–<?= $rows ? min($page * 10, $total) : 0 ?> dari <?= $total ?> · Halaman <?= $page ?> / <?= max(1, (int) ceil($total / 10)) ?></span>
        <div class="master-row-actions"><?php if ($page > 1): ?><a class="button button-outline" href="<?= escape($base . '?' . http_build_query(['q' => $query, 'page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?>
            <?php if ($page * 10 < $total): ?><a class="button button-outline" href="<?= escape($base . '?' . http_build_query(['q' => $query, 'page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?></div>
    </nav>
<?php elseif ($mode === 'edit'): ?>
    <h2><?= $item ? 'Edit' : 'Tambah' ?> <?= escape(strtolower($label)) ?></h2>
    <?php if ($item && (int) $item['used']): ?><div class="notice">Data sudah dipakai dan tidak dapat diedit. Gunakan arsip atau buat periode baru.</div><a href="<?= escape($base . '/' . $item['id']) ?>">Kembali ke detail</a>
    <?php else: ?>
        <form method="post" class="form-card" data-draft-form>
            <input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><?php if ($item): ?><input type="hidden" name="version" value="<?= (int) $item[$schoolType ? 'version' : 'management_version'] ?>"><?php endif; ?>
            <div class="form-grid">
                <?php if ($schoolType): ?>
                    <?php masterInput('name', 'Nama sekolah', $formData); masterInput('npsn', 'NPSN (8 digit)', $formData, 8); ?>
                    <div class="field"><label for="level">Jenjang</label><select id="level" name="level" required><?php foreach (['SD', 'SMP', 'SMA'] as $level): ?><option<?= $formData['level'] === $level ? ' selected' : '' ?>><?= $level ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="mode">Mode penerimaan</label><select id="mode" name="mode" required><option value="public_spmb"<?= $formData['mode'] === 'public_spmb' ? ' selected' : '' ?>>Negeri · SPMB</option><option value="private_independent"<?= $formData['mode'] === 'private_independent' ? ' selected' : '' ?>>Swasta · Mandiri</option></select></div>
                    <?php foreach (['province' => 'Provinsi', 'city' => 'Kabupaten / kota', 'district' => 'Kecamatan'] as $key => $text): masterInput($key, $text, $formData, 100); endforeach; masterInput('address', 'Alamat sekolah', $formData, 500, true); ?>
                <?php else: ?>
                    <div class="field field-wide"><label for="school_id">Sekolah</label><select id="school_id" name="school_id" required data-period-school><option value="">Pilih sekolah</option><?php foreach ($schools as $school): ?><option value="<?= escape($school['id']) ?>" data-mode="<?= escape($school['mode']) ?>" data-level="<?= escape($school['level']) ?>"<?= ($formData['school_id'] ?? '') === $school['id'] ? ' selected' : '' ?><?= !(int) $school['enabled'] ? ' disabled' : '' ?>><?= escape(admissionDisplayText($school['name']) . ' · ' . ($school['npsn'] ?? 'NPSN belum tersedia')) ?></option><?php endforeach; ?></select></div>
                    <?php masterInput('code', 'Kode periode unik (huruf kecil / angka / strip)', $formData, 50); masterInput('academic_year', 'Tahun ajaran (contoh 2026/2027)', $formData, 9); masterInput('organizer', 'Penyelenggara', $formData, 200); ?>
                    <div class="field"><label for="timezone">Zona waktu</label><select id="timezone" name="timezone"><?php foreach (['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'] as $zone): ?><option<?= $formData['timezone'] === $zone ? ' selected' : '' ?>><?= $zone ?></option><?php endforeach; ?></select></div>
                    <?php masterInput('opens_at', 'Pembukaan (YYYY-MM-DD HH:MM:SS)', $formData, 19); masterInput('closes_at', 'Penutupan (YYYY-MM-DD HH:MM:SS)', $formData, 19); ?>
                    <div class="field"><label for="admission_mode">Mode periode</label><select id="admission_mode" name="admission_mode"><option value="public_spmb"<?= ($formData['admission_mode'] ?? '') === 'public_spmb' ? ' selected' : '' ?>>Negeri · SPMB</option><option value="private_independent"<?= ($formData['admission_mode'] ?? '') === 'private_independent' ? ' selected' : '' ?>>Swasta · Mandiri</option></select></div>
                    <?php masterInput('help_contact', 'Kontak bantuan', $formData, 500); masterInput('rule_reference', 'Rujukan ketentuan / Juknis', $formData, 2000, true); masterInput('privacy_notice', 'Pemberitahuan privasi', $formData, 3000, true); ?>
                <?php endif; ?>
            </div>
            <?php if (!$schoolType): ?>
                <section data-pathway-editor><h2>Jalur dan persyaratan dokumen</h2>
                    <p class="field-help">Maksimal 20 jalur dan 10 dokumen per jalur. Mode negeri hanya domisili, afirmasi, prestasi, mutasi; prestasi tidak untuk SD. Periksa template terhadap Juknis; konfigurasi ini bukan pengesahan aturan.</p>
                    <div class="action-row"><label for="pathway-template">Template awal</label><select id="pathway-template" data-pathway-template><?php foreach (['negeri', 'swasta'] as $templateMode): foreach (['SD', 'SMP', 'SMA'] as $templateLevel): $template = admissionTemplate($templateMode, $templateLevel, new DateTimeImmutable()); ?><option value="<?= $templateMode . '-' . $templateLevel ?>" data-template-pathways="<?= escape(admissionJson($template['pathways'])) ?>"><?= ucfirst($templateMode) . ' · ' . $templateLevel ?></option><?php endforeach; endforeach; ?></select><button type="button" class="button button-outline" data-load-pathway-template>Ganti dengan template</button></div>
                    <p role="status" aria-live="polite" data-editor-status></p>
                    <div data-pathways><?php foreach ($formData['pathways'] as $routeIndex => $route): ?>
                        <fieldset class="pathway-editor-row" data-pathway><legend>Jalur <?= $routeIndex + 1 ?></legend>
                            <div class="form-grid"><div class="field"><label>Kode jalur<input name="pathways[<?= $routeIndex ?>][code]" data-route-field="code" maxlength="40" value="<?= escape($route['code']) ?>" required></label></div><div class="field"><label>Nama jalur<input name="pathways[<?= $routeIndex ?>][name]" data-route-field="name" maxlength="100" value="<?= escape($route['name']) ?>" required></label></div><div class="field field-wide"><label>Deskripsi<textarea name="pathways[<?= $routeIndex ?>][description]" data-route-field="description" maxlength="2000" rows="2"><?= escape($route['description']) ?></textarea></label></div></div>
                            <div data-documents><?php foreach ($route['documents'] as $docIndex => $doc): ?>
                                <div class="document-editor-row" data-document>
                                    <div class="field"><label>Kode dokumen<input name="pathways[<?= $routeIndex ?>][documents][<?= $docIndex ?>][code]" data-doc-field="code" maxlength="40" value="<?= escape($doc['code']) ?>" required></label></div>
                                    <div class="field"><label>Nama dokumen<input name="pathways[<?= $routeIndex ?>][documents][<?= $docIndex ?>][label]" data-doc-field="label" maxlength="150" value="<?= escape(admissionDisplayText($doc['label'])) ?>" required></label></div>
                                    <label class="document-required"><input type="checkbox" name="pathways[<?= $routeIndex ?>][documents][<?= $docIndex ?>][required]" data-doc-field="required" value="1"<?= $doc['required'] ? ' checked' : '' ?>> Wajib</label>
                                    <button type="button" class="button button-outline button-danger" data-remove-document>Hapus dokumen</button>
                                </div>
                            <?php endforeach; ?></div>
                            <div class="action-row"><button type="button" class="button button-outline" data-add-document>Tambah dokumen</button><button type="button" class="button button-outline button-danger" data-remove-pathway>Hapus jalur</button></div>
                        </fieldset>
                    <?php endforeach; ?></div>
                    <button type="button" class="button button-outline" data-add-pathway>Tambah jalur</button>
                    <noscript><p>Isian yang ada dapat diedit tanpa JavaScript. Aktifkan JavaScript untuk menambah/menghapus baris atau menggunakan template.</p></noscript>
                </section>
                <p class="notice">Periode baru disimpan sebagai arsip dan masih untuk pengujian. Aktifkan setelah memeriksa konfigurasi.</p>
            <?php endif; ?>
            <div class="form-actions"><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a><button class="button button-primary compact-button" type="submit">Simpan <?= escape(strtolower($label)) ?></button></div>
        </form>
    <?php endif; ?>
<?php elseif ($item): ?>
    <section class="form-card"><h2><?= escape(admissionDisplayText($schoolType ? $item['name'] : $item['school'])) ?></h2>
        <?php if ($mode === 'delete'): ?>
            <p>Hapus <?= escape(strtolower($label)) ?> secara permanen? Data yang sudah dipakai atau sekolah yang masih mempunyai periode tidak bisa dihapus.</p>
            <?php if ((int) $item['used'] || ($schoolType && (int) $item['periods'] > 0)): ?>
                <div class="notice">Penghapusan tidak tersedia karena data telah dipakai atau masih memiliki periode. Gunakan arsip melalui detail.</div><a class="button button-outline" href="<?= escape($base . '/' . $item['id']) ?>">Kembali ke detail</a>
            <?php else: ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><input type="hidden" name="version" value="<?= (int) $item[$schoolType ? 'version' : 'management_version'] ?>">
                <div class="privacy-check"><input id="confirm-delete" type="checkbox" name="confirm_delete" value="1" required><label for="confirm-delete">Saya menyetujui penghapusan permanen data ini.</label></div>
                <div class="form-actions"><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a><button type="submit" class="button button-outline button-danger">Ya, hapus data</button></div>
            </form>
            <?php endif; ?>
        <?php else: ?>
            <?php masterStatusSwitch($item, $type, $base . '/' . $item['id']); ?><p><?= (int) $item['used'] ? 'Sudah dipakai pendaftaran: edit/hapus ditolak.' : 'Belum dipakai pendaftaran.' ?></p>
            <dl class="summary-grid"><?php if ($schoolType): foreach (['npsn' => 'NPSN', 'level' => 'Jenjang', 'province' => 'Provinsi', 'city' => 'Kabupaten/kota', 'district' => 'Kecamatan', 'address' => 'Alamat'] as $key => $text): ?><div><dt><?= escape($text) ?></dt><dd><?= escape($item[$key] ?? 'Belum tersedia') ?></dd></div><?php endforeach; else: foreach (['code' => 'Kode periode', 'academic_year' => 'Tahun ajaran', 'organizer' => 'Penyelenggara'] as $key => $text): ?><div><dt><?= escape($text) ?></dt><dd><?= escape($item[$key]) ?></dd></div><?php endforeach; ?><div><dt>Jadwal</dt><dd><?= escape(admissionDate((int) $item['opens_at'], $item['timezone']) . ' — ' . admissionDate((int) $item['closes_at'], $item['timezone'])) ?></dd></div><?php endif; ?></dl>
            <?php if (!$schoolType): $rules = admissionPresentation($item)['configuration']; ?><h3>Jalur dan persyaratan</h3><?php foreach ($rules['pathways'] as $route): ?><h4><?= escape($route['name']) ?></h4><p><?= escape($route['description']) ?></p><ul class="checklist"><?php foreach ($route['documents'] as $doc): ?><li><?= escape($doc['label']) ?> · <?= $doc['required'] ? 'Wajib' : 'Opsional' ?></li><?php endforeach; ?></ul><?php endforeach; ?><h3>Ketentuan</h3><p><?= escape($rules['rule_reference']) ?></p><p><?= escape($rules['privacy_notice']) ?></p><p><?= escape($rules['help_contact']) ?></p><?php endif; ?>
            <div class="action-row"><a class="button button-outline" href="<?= escape($base) ?>">Kembali</a>
                <?php if (!(int) $item['used']): ?><a class="button button-outline" href="<?= escape($base . '/' . $item['id']) ?>/edit">Edit</a><?php endif; ?>
                <?php if (!$schoolType): ?><a class="button button-outline" href="<?= escape($base . '/new?copy=' . $item['id']) ?>">Salin menjadi periode baru</a><?php endif; ?>
                <?php if (!$schoolType): ?><a class="button button-outline" href="<?= escape('/admin/master-data/rules/new?period=' . $item['id']) ?>">Susun paket operasional</a><?php endif; ?>
            </div>
            <?php if ($schoolType): ?><p class="field-help">Mengarsipkan sekolah mengarsipkan seluruh periodenya juga. Mengaktifkan sekolah tidak otomatis mengaktifkan kembali periode.</p><?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
