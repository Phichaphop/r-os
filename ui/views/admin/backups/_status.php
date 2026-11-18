<?php
/** @var callable $ui @var callable $t @var string $bst */
$map = ['running' => ['info', 'loader-2'], 'completed' => ['warning', 'hourglass'], 'verified' => ['success', 'shield-check'], 'failed' => ['danger', 'alert-triangle'], 'deleted' => ['neutral', 'trash']];
[$tone, $icon] = $map[$bst] ?? ['neutral', 'info-circle'];
echo $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('backup.status.' . $bst)]);
