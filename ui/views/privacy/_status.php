<?php

/** @var callable $t @var callable $ui — defines $dsrBadge(status) */
$dsrBadge = static fn (string $s): string => $ui('badge', ['tone' => match ($s) {
'completed' => 'success', 'rejected' => 'neutral', 'erasure_pending' => 'warning', default => 'info'
}, 'label' => $t('privacy.status.' . $s)]);
