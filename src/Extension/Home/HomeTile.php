<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Home;

/**
 * A figure on the Home screen, beside the shop's own: a label, the value as text ("12", "€ 340", "3 open"), and
 * where it leads when clicked.
 */
final readonly class HomeTile
{
    public function __construct(
        public string $label,
        public string $value,
        /** A line under the value, such as "last 7 days"; empty for none. */
        public string $detail = '',
        /** A Shop page, an admin page, a path or an http(s) URL of the site (HomeUrl); empty for none. */
        public string $url = '',
    ) {}
}
