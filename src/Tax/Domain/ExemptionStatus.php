<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * Where a certificate stands: one a customer sent waits for the shop to check it; only an approved one exempts.
 */
enum ExemptionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
