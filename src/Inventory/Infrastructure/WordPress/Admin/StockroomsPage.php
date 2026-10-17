<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class StockroomsPage implements AdminPage
{
    public function slug(): string
    {
        return 'stockrooms';
    }

    public function title(): string
    {
        return __('Stockrooms', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'stockrooms';
    }

    public function menuOrder(): int
    {
        return 70;
    }
}
