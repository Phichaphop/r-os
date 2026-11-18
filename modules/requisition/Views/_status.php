<?php
/** Status badge of a request. @var callable $ui @var callable $t @var string $st */
$tone = ['draft' => 'neutral', 'pending' => 'info', 'approved' => 'success', 'partial' => 'warning', 'fulfilled' => 'success', 'closed' => 'neutral', 'rejected' => 'danger', 'cancelled' => 'neutral', 'expired' => 'warning'][$st] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $tone, 'label' => $t('requisition.status.' . $st)]) ?>
