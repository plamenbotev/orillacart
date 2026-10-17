<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class ShippingPage implements AdminPage
{
    public function slug(): string
    {
        return 'shipping';
    }

    public function title(): string
    {
        return __('Shipping', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'shipping';
    }

    public function menuOrder(): int
    {
        return 80;
    }
}
