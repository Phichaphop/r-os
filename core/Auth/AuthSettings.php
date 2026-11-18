<?php

declare(strict_types=1);

namespace Blue\Core\Auth;

use Blue\Core\Config\AppConfig;

/** DEC-51 values, read once from configuration (phase 3: Config Registry with effective dates). */
final class AuthSettings
{
    public function __construct(
        public readonly int $passwordMin = 12,
        public readonly int $idleMinutes = 30,
        public readonly int $absoluteHours = 12,
        public readonly int $lockFailures = 5,
        public readonly int $lockWindowMinutes = 15,
        public readonly int $lockMinutes = 15,
        public readonly int $ipFailures = 30,
        public readonly int $activationHours = 72,
        public readonly int $resetOtpMinutes = 10,
        public readonly int $otpAttempts = 5,
        public readonly int $reauthMinutes = 5,
    ) {
    }

    public static function fromConfig(AppConfig $c): self
    {
        return new self(
            max(8, $c->int('AUTH_PASSWORD_MIN', 12)),
            $c->int('AUTH_IDLE_MINUTES', 30),
            $c->int('AUTH_ABSOLUTE_HOURS', 12),
            $c->int('AUTH_LOCK_FAILURES', 5),
            $c->int('AUTH_LOCK_WINDOW_MINUTES', 15),
            $c->int('AUTH_LOCK_MINUTES', 15),
            $c->int('AUTH_IP_FAILURES', 30),
            $c->int('AUTH_ACTIVATION_HOURS', 72),
            $c->int('AUTH_RESET_OTP_MINUTES', 10),
            $c->int('AUTH_OTP_ATTEMPTS', 5),
            $c->int('AUTH_REAUTH_MINUTES', 5),
        );
    }
}
