<?php

/**
 * Staging/UAT + DR (DEC-40). Since 0.50.0 (DEC-119 · Q-17/Q-18) Staging is the Katana 17 B13V notebook (XAMPP, this
 * machine only) and holds real data until a production server exists: install it WITHOUT demo data —
 * `php bin/blue doctor` warns while demo data is present (docs/STAGING_KATANA17.md).
 */
return [
    'SCHEDULER' => 'cron',
    'UI_SHOWCASE' => true,
    'DEMO_DATA_ALLOWED' => true,
];
