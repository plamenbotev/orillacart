<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/**
 * Shop → Customers → Accounts and groups: whether customers have accounts, the newsletter, and the customer
 * groups. The customers' script serves it (design 52).
 */
final readonly class CustomerSettingsPage implements AdminPage
{
    public const string SLUG = 'customer-settings';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function title(): string
    {
        return __('Accounts and groups', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'customers';
    }

    public function group(): MenuGroup
    {
        return MenuGroup::Customers;
    }

    public function menuOrder(): int
    {
        return 2;
    }
}
