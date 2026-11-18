<?php
/** @var callable $ui @var callable $t @var string $status */
$tone = ['confirmed' => 'info', 'checked_in' => 'success', 'completed' => 'neutral', 'cancelled' => 'neutral', 'no_show' => 'warning'][$status] ?? 'neutral';
echo $ui('badge', ['tone' => $tone, 'label' => $t('rooms.status.' . $status)]);
