<?php
/** Status badge of a request. @var callable $ui @var callable $t @var string $st */
$tone = match ($st) {
    'received' => 'success', 'ready' => 'info', 'need_info' => 'warning', 'cancelled' => 'neutral', default => 'neutral',
};
echo $ui('badge', ['tone' => $tone, 'label' => $t('certs.status.' . $st)]);
