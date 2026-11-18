<?php
/** Permit status badge. @var callable $ui @var callable $t @var string $pst */
$ptone = ['draft' => 'neutral', 'review' => 'info', 'approved' => 'success', 'on_site' => 'warning', 'completed' => 'neutral', 'rejected' => 'danger', 'cancelled' => 'neutral', 'expired' => 'neutral', 'suspended' => 'danger'][$pst] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $ptone, 'label' => $t('contractors.status.' . $pst)]) ?>
