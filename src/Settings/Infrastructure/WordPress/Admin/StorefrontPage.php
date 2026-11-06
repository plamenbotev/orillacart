<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** Reviews, what the shop tells search engines and social sites, wishlists and the store notice: a tab of Settings. Served by the settings script, which mounts a screen per page by its element id. */
final readonly class StorefrontPage implements AdminPage
{
    public function slug(): string
    {
        return 'storefront';
    }

    public function title(): string
    {
        return __('Storefront', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'settings';
    }

    public function group(): MenuGroup
    {
        return MenuGroup::Settings;
    }

    public function menuOrder(): int
    {
        return 4;
    }
}
