<?php
/** @var callable $t @var callable $ui — defines $stBadge(status) */
$stBadge = static fn (string $s): string => $ui('badge', ['tone' => match ($s) { 'completed' => 'success', 'sent', 'partial' => 'warning', 'draft' => 'info', default => 'neutral' }, 'label' => $t('esign.status.' . $s)]);
