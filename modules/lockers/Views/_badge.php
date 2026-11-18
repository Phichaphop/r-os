<?php
/** @var callable $ui @var callable $t @var string $lstatus */
$tone = ['available' => 'success', 'reserved' => 'info', 'assigned' => 'neutral', 'return_pending' => 'warning', 'maintenance' => 'warning', 'blocked' => 'danger'][$lstatus] ?? 'neutral';
echo $ui('badge', ['tone' => $tone, 'label' => $t('lockers.status.' . $lstatus)]);
