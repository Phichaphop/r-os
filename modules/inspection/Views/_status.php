<?php
/** Round status (and result) badges. @var callable $ui @var callable $t @var array<string, mixed> $row */
$st = (string) $row['status'];
$tone = ['scheduled' => 'info', 'in_progress' => 'warning', 'submitted' => 'info', 'reviewed' => 'success', 'closed' => 'neutral', 'overdue' => 'danger'][$st] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $tone, 'label' => $t('inspection.status.' . $st)]) ?><?php if (($row['result'] ?? null) !== null): ?> <?= $ui('badge', ['tone' => $row['result'] === 'pass' ? 'success' : 'danger', 'label' => $t('inspection.result.' . $row['result']) . ((int) ($row['fail_count'] ?? 0) > 0 ? ' ' . (int) $row['fail_count'] : '')]) ?><?php endif; ?>
