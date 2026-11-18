<?php
/** Enrolment status badge. @var callable $ui @var callable $t @var string $st */
echo $ui('badge', ['tone' => match ($st) {
    'passed' => 'success', 'enrolled', 'attended' => 'info', 'pending', 'waitlisted' => 'warning', 'failed', 'no_show' => 'danger', default => 'neutral',
}, 'label' => $t('training.enr.' . $st)]);
