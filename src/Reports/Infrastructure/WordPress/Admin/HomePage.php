<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** Shop → Home: what the shop did today, what needs the shop manager, the latest orders. The menu's first entry. */
final readonly class HomePage implements AdminPage
{
    public const string SLUG = 'home';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function title(): string
    {
        return __('Home', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'home';
    }

    public function group(): MenuGroup
    {
        return MenuGroup::Home;
    }

    public function menuOrder(): int
    {
        return 1;
    }
}
