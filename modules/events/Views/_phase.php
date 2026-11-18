<?php
/** Event phase badge. @var callable $ui @var callable $t @var string $eph */
$etone = ['draft' => 'neutral', 'open' => 'success', 'full' => 'warning', 'closed' => 'neutral', 'in_progress' => 'info', 'completed' => 'neutral', 'cancelled' => 'danger'][$eph] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $etone, 'label' => $t('events.phase.' . $eph)]) ?>
