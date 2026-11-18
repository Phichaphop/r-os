<?php
/** Survey status badge. @var callable $ui @var callable $t @var string $sst */
$stone = ['draft' => 'neutral', 'open' => 'success', 'closed' => 'info', 'analyzed' => 'neutral'][$sst] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $stone, 'label' => $t('kaizen.survey_status.' . $sst)]) ?>
