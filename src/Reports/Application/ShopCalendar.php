<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

/**
 * The shop's calendar: reports count days as the shop lives them, in its own time zone.
 */
interface ShopCalendar
{
    public function timezone(): \DateTimeZone;
}
