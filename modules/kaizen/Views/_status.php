<?php
/** Idea status badge. @var callable $ui @var callable $t @var string $ist */
$itone = ['submitted' => 'info', 'screening' => 'info', 'evaluation' => 'info', 'accepted' => 'success', 'rejected' => 'danger', 'implementing' => 'warning', 'verified' => 'success', 'closed' => 'neutral'][$ist] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $itone, 'label' => $t('kaizen.status.' . $ist)]) ?>
