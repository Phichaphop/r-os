<?php
/** Requisition status badge. @var callable $ui @var callable $t @var string $rs */
$tone = ['draft' => 'neutral', 'pending' => 'info', 'approved' => 'success', 'rejected' => 'danger', 'returned' => 'warning', 'filled' => 'success', 'cancelled' => 'neutral'][$rs] ?? 'neutral';
echo $ui('badge', ['tone' => $tone, 'label' => $t('recruit.req_status.' . $rs)]);
