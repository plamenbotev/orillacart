<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Shared\WordPress\ScheduledTask;
use OrillaCart\Tax\Application\ExemptionCertificates;

/**
 * Every day: tells customers whose approved certificates run out within a month, once each, to send the new one.
 */
final readonly class RemindOfExpiringExemptions implements ScheduledTask
{
    public function __construct(private ExemptionCertificates $certificates) {}

    public static function hook(): string
    {
        return 'orillacart_remind_of_expiring_exemptions';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        while ($this->certificates->remindExpiring() > 0);
    }
}
