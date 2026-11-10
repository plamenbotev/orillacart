<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Home;

/**
 * A line of the Home screen's "Needs you" list: what waits, how many, and where to act. Give only lines for
 * things that wait: the list is empty when nothing needs the shop manager.
 */
final readonly class HomeLine
{
    public const string ATTENTION = 'attention';
    public const string WARNING = 'warning';
    public const string INFO = 'info';

    public function __construct(
        /** What waits: "3 returns to accept". */
        public string $text,
        /** Where to act: a Shop page, an admin page, a path or an http(s) URL of the site (HomeUrl); empty for none. */
        public string $url = '',
        /** How pressing: ATTENTION (red), WARNING (amber) or INFO (blue). */
        public string $tone = self::WARNING,
    ) {}
}
