<?php
/** Campaign status badge. @var callable $ui @var callable $t @var string $cst */
$tone = ['draft' => 'neutral', 'counting' => 'info', 'reviewing' => 'warning', 'closed' => 'success'][$cst] ?? 'neutral';
?>
<?= $ui('badge', ['tone' => $tone, 'label' => $t('assets.campaign_status.' . $cst)]) ?>
