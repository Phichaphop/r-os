<?php
/** Status badge of an asset. @var callable $ui @var callable $t @var string $st */
$tone = ['available' => 'success', 'assigned' => 'info', 'maintenance' => 'warning', 'lost' => 'danger', 'disposal_pending' => 'warning', 'disposed' => 'neutral'][$st] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $tone, 'label' => $t('assets.status.' . $st)]) ?>
