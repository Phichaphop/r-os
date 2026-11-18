<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url */
?>
<?= $ui('states', ['kind' => 'empty', 'icon' => 'calculator', 'title' => $t('calc.share_gone'), 'text' => $t('calc.share_gone_text'),
    'action' => $ui('button', ['label' => $t('calc.back'), 'href' => $url('/calc'), 'variant' => 'secondary'])]) ?>
