<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** What few shops change: how downloads are sent. The last tab of Settings. Served by the settings script, which mounts a screen per page by its element id. */
final readonly class AdvancedPage implements AdminPage
{
    public function slug(): string
    {
        return 'advanced';
    }

    public function title(): string
    {
        return __('Advanced', 'orillacart');
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
        return 11;
    }
}
