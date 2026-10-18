<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Invoicing;

use OrillaCart\Reports\Application\ShopCalendar;

/** The shop's days are Sofia's. */
final class SofiaCalendar implements ShopCalendar
{
    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Sofia');
    }
}
