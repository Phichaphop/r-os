<?php
/** Leave status badge helper. Returns a closure $leaveStatus(string $status): string. @var callable $ui @var callable $t */
$leaveStatus = static function (string $s) use ($ui, $t): string {
    [$tone, $icon] = [
        'pending' => ['warning', 'hourglass'], 'approved' => ['success', 'circle-check'], 'taken' => ['success', 'checks'], 'returned' => ['warning', 'arrow-back-up'],
        'rejected' => ['danger', 'circle-x'], 'cancelled' => ['neutral', 'x'], 'cancel_pending' => ['warning', 'hourglass'],
    ][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('leave.status.' . $s)]);
};
