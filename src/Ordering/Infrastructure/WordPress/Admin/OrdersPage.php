<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class OrdersPage implements AdminPage
{
    public function slug(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return __('Orders', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'orders';
    }

    public function menuOrder(): int
    {
        return 5;
    }
}
