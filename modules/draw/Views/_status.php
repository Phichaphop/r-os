<?php
/** @var callable $ui @var callable $t @var string $status */
$tone = ['draft' => ['neutral', 'pencil'], 'ready' => ['info', 'lock'], 'drawing' => ['warning', 'gift'], 'confirmed' => ['success', 'circle-check'], 'archived' => ['neutral', 'history']];
[$tn, $ic] = $tone[$status] ?? ['neutral', 'info-circle'];
echo $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('draw.status.' . $status)]);
