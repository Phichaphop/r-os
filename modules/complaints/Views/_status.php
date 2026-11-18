<?php
/** @var callable $ui @var callable $t @var string $s */
$tone = ['submitted' => 'info', 'triage' => 'info', 'investigating' => 'warning', 'action_required' => 'danger', 'resolved' => 'success', 'closed' => 'neutral', 'duplicate' => 'neutral', 'out_of_scope' => 'neutral'];
echo $ui('badge', ['tone' => $tone[$s] ?? 'neutral', 'label' => $t('complaints.status.' . $s)]);
