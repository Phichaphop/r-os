<?php

/** On-prem production (DEC-40). Real data → no showcase, no demo data. */
return [
    'SCHEDULER' => 'cron',
    'UI_SHOWCASE' => false,
    'DEMO_DATA_ALLOWED' => false,
    'LOG_LEVEL' => 'warning',
    'MAIL_DRIVER' => 'none', // until the Brevo adapter passes privacy review (DEC-48)
];
