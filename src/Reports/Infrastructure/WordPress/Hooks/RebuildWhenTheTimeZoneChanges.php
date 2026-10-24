<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\WordPress\Hooks;

use OrillaCart\Reports\Application\Reports;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Orders count on the shop's day, in its time zone: when the site's time zone changes (Settings → General), the
 * reports are built again, so an order of the evening is not left on the next day.
 */
#[AsHook('update_option_timezone_string', arguments: 2)]
#[AsHook('update_option_gmt_offset', arguments: 2)]
final readonly class RebuildWhenTheTimeZoneChanges
{
    public function __construct(private Reports $reports) {}

    public function __invoke(mixed $before, mixed $after): void
    {
        if ($before !== $after) {
            $this->reports->rebuild();
        }
    }
}
