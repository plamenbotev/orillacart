<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** The shop's filters and its search engine: a tab of Catalog. Served by the settings script, which mounts a screen per page by its element id. */
final readonly class FiltersPage implements AdminPage
{
    public function slug(): string
    {
        return 'filters';
    }

    public function title(): string
    {
        return __('Filters and search', 'orillacart');
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
        return MenuGroup::Catalog;
    }

    public function menuOrder(): int
    {
        return 6;
    }
}
