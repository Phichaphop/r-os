<?php
/** @var callable $ui @var callable $t @var string $st */
$map = ['draft' => 'draft', 'in_review' => 'pending', 'returned' => 'returned', 'approved' => 'approved', 'file_generated' => ['info', 'file-text'], 'sent' => ['info', 'send'],
    'reconciled' => ['success', 'checks'], 'cancelled' => 'cancelled'];
$m = $map[$st] ?? 'draft';
echo is_array($m) ? $ui('badge', ['tone' => $m[0], 'icon' => $m[1], 'label' => $t('payment.status.' . $st)]) : $ui('badge', ['status' => $m, 'label' => $t('payment.status.' . $st)]);
