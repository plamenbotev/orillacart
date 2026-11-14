<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/**
 * The shop's calendar: reports, invoices, delivery days and the rest count days as the shop lives them, in its
 * own time zone.
 */
interface ShopCalendar
{
    public function timezone(): \DateTimeZone;
}
