<?php
/** @var callable $ui @var callable $t @var string $s */
$tone = ['invited' => 'info', 'checked_in' => 'success', 'checked_out' => 'neutral', 'cancelled' => 'neutral', 'denied' => 'danger', 'expired' => 'warning'];
echo $ui('badge', ['tone' => $tone[$s] ?? 'neutral', 'label' => $t('visitors.status.' . $s)]);
