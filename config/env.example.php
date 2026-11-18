<?php

/**
 * Copy to config/env.php (gitignored) on each server, or let the installer write it.
 * NEVER commit the real file.
 */

return [
    'APP_ENV' => 'local',          // local | ci | prototype | staging | production
    'APP_KEY' => 'base64:CHANGE_ME_32_RANDOM_BYTES',
    'APP_DEBUG' => true,           // honoured only in local/ci
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => 3306,
    'DB_NAME' => 'blue_dev',
    'DB_USER' => 'blue',
    'DB_PASS' => 'change-me',
];
