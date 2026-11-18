<?php

declare(strict_types=1);

namespace Blue\Core\Mail;

use Blue\Core\Database\Connection;
use Blue\Core\Support\Clock;

/**
 * Outgoing mail (6.2, 6.12). Drivers:
 * - dev_mailbox: stored in mail_outbox for 24 h and shown at /system/mailbox (local/prototype/staging only)
 * - none:        nothing is sent; features that need mail tell the user to contact an administrator
 * The Brevo API adapter (DEC-48) is added with the Notification service in phase 3, after privacy review.
 */
final class Mailer
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly string $driver,
    ) {
    }

    public function available(): bool
    {
        return $this->driver === 'dev_mailbox';
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->driver !== 'dev_mailbox') {
            return false;
        }
        $now = $this->clock->now();
        $this->db->run('DELETE FROM mail_outbox WHERE expires_at < ?', [$now->format('Y-m-d H:i:s.u')]);
        $this->db->run(
            'INSERT INTO mail_outbox (to_address, subject, body, channel, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$to, mb_substr($subject, 0, 200), $body, 'dev_mailbox', $now->format('Y-m-d H:i:s.u'), $now->modify('+24 hours')->format('Y-m-d H:i:s.u')]
        );
        return true;
    }
}
