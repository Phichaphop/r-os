<?php
/**
 * Badge for a config version status (draft … active/superseded). Include with $st set.
 *
 * @var callable $ui @var callable $t @var string $st
 */
$map = [
    'draft' => ['neutral', 'pencil'], 'in_review' => ['info', 'hourglass'], 'returned' => ['warning', 'arrow-back-up'],
    'rejected' => ['danger', 'circle-x'], 'cancelled' => ['neutral', 'x'], 'scheduled' => ['info', 'calendar-event'],
    'active' => ['success', 'circle-check'], 'superseded' => ['neutral', 'history'], 'expired' => ['neutral', 'clock'], 'approved' => ['success', 'circle-check'],
];
[$tone, $icon] = $map[$st] ?? ['neutral', 'info-circle'];
echo $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('config.status.' . $st)]);
