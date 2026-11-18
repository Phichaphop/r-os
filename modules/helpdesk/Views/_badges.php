<?php
/** Status and priority badges of a ticket row. @var callable $ui @var callable $t @var array<string, mixed> $row */
$st = (string) $row['status'];
$tone = ['open' => 'info', 'assigned' => 'info', 'in_progress' => 'warning', 'waiting_user' => 'warning', 'waiting_parts' => 'warning', 'resolved' => 'success', 'closed' => 'neutral', 'cancelled' => 'neutral'][$st] ?? 'neutral';
$pt = ['low' => 'neutral', 'normal' => 'info', 'high' => 'warning', 'urgent' => 'danger'][(string) $row['priority']] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $tone, 'label' => $t('helpdesk.status.' . $st)]) ?> <?= $ui('badge', ['tone' => $pt, 'label' => $t('helpdesk.priority.' . $row['priority'])]) ?>
