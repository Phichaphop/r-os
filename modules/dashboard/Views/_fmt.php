<?php
/**
 * Shared formatting for KPI values. Defines $fmt(value, unit) and $statusTone(status).
 * @var callable $t
 */
$fmt = static function (mixed $v, string $unit) use ($t): string {
    if ($v === null || $v === '') {
        return '—';
    }
    $v = (float) $v;
    $n = number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2);
    return match ($unit) {
        'percent' => $n . '%',
        'hours', 'days' => $n . ' ' . $t('dashboard.unit_short.' . $unit),
        default => $n,
    };
};
$statusTone = static fn (string $s): string => match ($s) { 'fresh' => 'success', 'stale' => 'warning', 'partial' => 'info', 'error' => 'danger', default => 'neutral' };
