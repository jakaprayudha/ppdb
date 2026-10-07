<?php declare(strict_types=1); ?>
<p class="lead">Admin pusat mengelola staf. Satu staf dapat ditugaskan ke beberapa sekolah. Admin sekolah tidak mengubah master; verifikator hanya memeriksa peserta yang ditugaskan kepadanya.</p>
<?php if (!$editStaff): ?>
<section class="form-card"><h2>Admin pusat</h2><?php foreach ($rows as $row): ?><p><?= escape($row['name'] . ' · ' . $row['email']) ?></p><?php endforeach; ?><p class="field-help">Akun pusat tetap dikelola melalui CLI; formulir ini tidak mempromosikan wali atau membuat admin pusat.</p></section>
<?php endif; ?>
<form method="post" class="form-card">
<h2><?= $editStaff ? 'Edit akun & penugasan sekolah' : 'Undang staf' ?></h2>
<input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>">
<?php if ($editStaff): ?><input type="hidden" name="version" value="<?= (int) $editStaff['version'] ?>"><p><?= escape($editStaff['name'] . ' · ' . $editStaff['email']) ?></p>
<?php else: ?><div class="form-grid">
<div class="field"><label for="staff-name">Nama lengkap</label><input id="staff-name" name="name" value="<?= escape($values['name']) ?>" minlength="2" maxlength="100" required></div>
<div class="field"><label for="staff-email">Email staf</label><input type="email" id="staff-email" name="email" value="<?= escape($values['email']) ?>" maxlength="254" required></div></div><?php endif; ?>
<div class="field"><label for="staff-role">Peran</label><select id="staff-role" name="role" required>
<?php foreach (['school_admin','verifier'] as $role): ?><option value="<?= $role ?>"<?= $values['role'] === $role ? ' selected' : '' ?>><?= escape(staffRoleLabel($role)) ?></option><?php endforeach; ?></select></div>
<fieldset class="staff-school-picker"><legend>Sekolah yang ditugaskan (minimal satu)</legend>
<?php foreach ($schools as $school): ?><label class="checkbox-field"><input type="checkbox" name="schools[]" value="<?= escape($school['id']) ?>"<?= is_array($values['schools']) && in_array($school['id'], $values['schools'], true) ? ' checked' : '' ?>> <?= escape(admissionDisplayText($school['name']) . ' · ' . ($school['npsn'] ?? 'NPSN belum diisi')) ?></label><?php endforeach; ?></fieldset>
<?php if ($editStaff): ?><label class="checkbox-field"><input type="checkbox" name="enabled" value="1"<?= (int) $values['enabled'] ? ' checked' : '' ?>> Akun aktif</label><p class="field-help">Menyimpan perubahan mencabut seluruh sesi staf dan melepas penugasan peserta. Akun nonaktif tidak dapat login; data keputusan lama tetap tersimpan.</p><?php endif; ?>
<label class="checkbox-field"><input type="checkbox" name="can_approve" value="1"<?= (int) $values['can_approve'] ? ' checked' : '' ?>> Approver paket aturan sekolah</label>
<p class="field-help">Dapat menyetujui atau mengembalikan paket aturan sekolah yang ditugaskan, bukan paket yang disusun/diajukan sendiri. Penerbitan tetap oleh admin pusat. Hak ini belum mencakup persetujuan hasil seleksi.</p>
<div class="action-row"><button class="button button-primary compact-button"><?= $editStaff ? 'Simpan akses' : 'Kirim undangan' ?></button><?php if ($editStaff): ?><a href="/admin/accounts" class="button button-outline">Kembali</a><?php endif; ?></div>
</form>
<?php if (!$editStaff): ?>
<form method="get" class="form-card"><div class="field"><label for="q">Cari nama / email staf</label><input id="q" name="q" value="<?= escape($query) ?>"></div><button class="button button-primary compact-button">Cari</button></form>
<div class="master-table-wrap" role="region" aria-label="Tabel staf" tabindex="0"><table class="master-table"><caption>Staf · <?= $total ?> akun</caption>
<thead><tr><th>Nama / email</th><th>Peran</th><th>Sekolah</th><th>Status / keamanan</th><th>Aksi</th></tr></thead>
<tbody><?php foreach ($staffRows as $row): ?><tr><td><?= escape($row['name']) ?><small><?= escape($row['email']) ?></small></td><td><?= escape(staffRoleLabel($row['role'])) ?><?php if ((int) $row['can_approve']): ?><small>Approver aturan</small><?php endif; ?></td>
<td><?= escape(implode(', ', array_map('admissionDisplayText', $row['schools']))) ?></td><td><?= (int) $row['enabled'] ? 'Aktif' : 'Nonaktif' ?><small><?= $row['email_verified_at'] ? 'Email terverifikasi' : 'Email belum terverifikasi' ?> · <?= (int) $row['mfa_enabled'] ? 'MFA aktif' : 'MFA belum diatur' ?></small></td><td><a class="button button-outline" href="/admin/accounts/<?= (int) $row['id'] ?>">Edit akses</a></td></tr><?php endforeach; ?>
<?php if (!$staffRows): ?><tr><td colspan="5">Belum ada staf yang cocok.</td></tr><?php endif; ?></tbody></table></div>
<nav class="master-pagination" aria-label="Pagination staf"><span>Halaman <?= $page ?> / <?= max(1, (int) ceil($total / 10)) ?></span><div class="action-row">
<?php if ($page > 1): ?><a href="/admin/accounts?<?= escape(http_build_query(['q'=>$query,'page'=>$page-1])) ?>">Sebelumnya</a><?php endif; ?>
<?php if ($page * 10 < $total): ?><a href="/admin/accounts?<?= escape(http_build_query(['q'=>$query,'page'=>$page+1])) ?>">Berikutnya</a><?php endif; ?></div></nav>
<section class="form-card"><h2>Undangan belum digunakan</h2><p class="field-help">Maksimal 50 undangan terakhir. Batalkan undangan aktif sebelum mengirim ulang.</p>
<?php foreach ($invitations as $invite): ?><article class="document-card"><h3><?= escape($invite['name']) ?></h3><p><?= escape($invite['email'] . ' · ' . staffRoleLabel($invite['role'])) ?> · <?= (int) $invite['expires_at'] > time() ? 'Menunggu aktivasi' : 'Kedaluwarsa' ?></p>
<form method="post" action="/admin/accounts/invitations/<?= escape($invite['id']) ?>/cancel"><input type="hidden" name="csrf" value="<?= escape(csrfToken()) ?>"><button class="button button-outline button-danger">Batalkan undangan</button></form></article><?php endforeach; ?>
<?php if (!$invitations): ?><p>Tidak ada undangan tertunda.</p><?php endif; ?></section>
<?php endif; ?>
