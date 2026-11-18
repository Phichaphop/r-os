<?php
/** @var callable $ui @var callable $t @var string $status */
$tone = ['validation_failed' => 'danger', 'ready' => 'info', 'pending' => 'warning', 'returned' => 'warning', 'approved' => 'success', 'exported' => 'success',
    'submitted' => 'success', 'accepted' => 'success', 'rejected' => 'danger', 'superseded' => 'neutral'][$status] ?? 'neutral';
echo $ui('badge', ['tone' => $tone, 'label' => $t('filing.status.' . $status)]);
