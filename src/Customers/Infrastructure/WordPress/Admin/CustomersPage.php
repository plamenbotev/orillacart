<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class CustomersPage implements AdminPage
{
    public function slug(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return __('Customers', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'customers';
    }

    public function menuOrder(): int
    {
        return 20;
    }
}
