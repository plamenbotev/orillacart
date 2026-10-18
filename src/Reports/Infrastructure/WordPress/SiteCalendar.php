<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\WordPress;

use OrillaCart\Reports\Application\ShopCalendar;

/**
 * The site's time zone, as Settings → General sets it.
 */
final readonly class SiteCalendar implements ShopCalendar
{
    public function timezone(): \DateTimeZone
    {
        return wp_timezone();
    }
}
