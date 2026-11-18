<?php
/**
 * Small renderers shared by the employee views. @var callable $e @var callable $t @var callable $ui
 */
$statusBadge = static function (string $s) use ($ui, $t): string {
    $tone = ['active' => ['success', 'circle-check'], 'pre_hire' => ['info', 'hourglass'], 'on_leave' => ['info', 'calendar-event'],
        'suspended' => ['warning', 'alert-circle'], 'former' => ['neutral', 'history']][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone[0], 'icon' => $tone[1], 'label' => $t('employees.status.' . $s)]);
};
$verifyBadge = static function (string $s) use ($ui, $t): string {
    $tone = ['verified' => ['success', 'circle-check'], 'pending' => ['warning', 'hourglass'], 'rejected' => ['danger', 'circle-x']][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone[0], 'icon' => $tone[1], 'label' => $t('employees.verify.' . $s)]);
};
$reqBadge = static function (string $s) use ($ui, $t): string {
    $tone = ['pending' => ['warning', 'hourglass'], 'approved' => ['info', 'clock'], 'applied' => ['success', 'circle-check'], 'returned' => ['warning', 'arrow-back-up'],
        'rejected' => ['danger', 'circle-x'], 'withdrawn' => ['neutral', 'x'], 'failed' => ['danger', 'alert-triangle']][$s] ?? ['neutral', 'info-circle'];
    return $ui('badge', ['tone' => $tone[0], 'icon' => $tone[1], 'label' => $t('employees.req_status.' . $s)]);
};
