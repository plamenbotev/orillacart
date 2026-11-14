<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Settings\Application\ShopCalendar;

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
