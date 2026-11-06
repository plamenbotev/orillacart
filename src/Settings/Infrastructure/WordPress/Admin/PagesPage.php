<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** The shop's pages and the legal ones, and what checkout asks: a tab of Settings. Served by the settings script, which mounts a screen per page by its element id. */
final readonly class PagesPage implements AdminPage
{
    public function slug(): string
    {
        return 'pages';
    }

    public function title(): string
    {
        return __('Pages and legal', 'orillacart');
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
        return 2;
    }
}
