<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** The seller's details on invoices and how they are numbered and sent: a tab of Settings. Served by the settings script, which mounts a screen per page by its element id. */
final readonly class InvoicesPage implements AdminPage
{
    public function slug(): string
    {
        return 'invoices';
    }

    public function title(): string
    {
        return __('Invoices', 'orillacart');
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
        return 3;
    }
}
