<?php
/** Status badge of a booking. @var callable $ui @var callable $t @var string $st */
$tone = ['draft' => 'neutral', 'pending' => 'info', 'approved' => 'info', 'assigned' => 'success', 'in_progress' => 'warning', 'completed' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', 'no_show' => 'danger'][$st] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $tone, 'label' => $t('vehicles.status.' . $st)]) ?>
