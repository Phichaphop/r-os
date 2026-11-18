<?php
/**
 * Status badge — always icon + text + colour (P0-05 §3.4).
 * Props: status (key in the standard set) OR tone + label + icon
 *
 * @var array<string, mixed> $p
 * @var callable $e
 * @var callable $t
 * @var callable $ui
 */
$standard = [
    'draft' => ['neutral', 'pencil'], 'submitted' => ['info', 'send'], 'pending' => ['warning', 'hourglass'],
    'returned' => ['warning', 'arrow-back-up'], 'approved' => ['success', 'circle-check'], 'rejected' => ['danger', 'circle-x'],
    'cancelled' => ['neutral', 'x'], 'locked' => ['info', 'lock'], 'posted' => ['success', 'checks'],
    'reversed' => ['neutral', 'arrow-back-up'], 'paid' => ['success', 'cash'], 'failed' => ['danger', 'alert-triangle'],
    'pending_sync' => ['warning', 'cloud-off'], 'sync_failed' => ['danger', 'alert-circle'], 'processing' => ['info', 'loader-2'],
];
$status = (string) ($p['status'] ?? '');
[$tone, $icon] = $standard[$status] ?? [$p['tone'] ?? 'neutral', $p['icon'] ?? 'info-circle'];
$label = $p['label'] ?? $t('status.' . $status);
?>
<span class="badge badge--<?= $e($tone) ?>"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?><?= $e($label) ?></span>
