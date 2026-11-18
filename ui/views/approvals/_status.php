<?php
/**
 * Badge for a request (workflow instance) status. Include with $wfStatus and optional $wfExec set.
 *
 * @var callable $ui @var callable $t @var string $wfStatus
 */
$map = [
    'pending' => ['info', 'hourglass'], 'approved' => ['success', 'circle-check'], 'rejected' => ['danger', 'circle-x'],
    'returned' => ['warning', 'arrow-back-up'], 'cancelled' => ['neutral', 'x'], 'needs_attention' => ['danger', 'alert-triangle'],
];
[$tone, $icon] = $map[$wfStatus] ?? ['neutral', 'info-circle'];
echo $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('workflow.status.' . $wfStatus)]);
if ($wfStatus === 'approved' && ($wfExec ?? '') === 'failed') {
    echo ' ' . $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('workflow.exec.failed')]);
}
