<?php
/** @var callable $ui @var callable $t @var string $status */
$tone = ['draft' => 'neutral', 'calculated' => 'info', 'pending' => 'warning', 'approved' => 'success', 'cancelled' => 'neutral'][$status] ?? 'neutral';
echo $ui('badge', ['tone' => $tone, 'label' => $t('payroll.status.' . $status)]);
