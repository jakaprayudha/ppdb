<?php
declare(strict_types=1);

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
