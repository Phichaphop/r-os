<?php
/** @var callable $ui @var callable $t @var string $st document lifecycle status */
$map = ['draft' => ['neutral', 'pencil'], 'final' => ['success', 'lock'], 'superseded' => ['info', 'arrow-back-up'], 'archived' => ['neutral', 'database'], 'disposed' => ['danger', 'trash']];
[$tone, $icon] = $map[$st] ?? ['neutral', 'info-circle'];
echo $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('document.status.' . $st)]);
