<?php
/** Dates and times of a session. @var callable $date @var array<string, mixed> $x */
echo $date((string) $x['start_date'], false) . ((string) $x['end_date'] !== (string) $x['start_date'] ? ' – ' . $date((string) $x['end_date'], false) : '') . ' · ' . $x['start_time'] . '–' . $x['end_time'];
