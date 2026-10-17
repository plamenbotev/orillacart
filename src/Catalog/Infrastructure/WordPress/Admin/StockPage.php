<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class StockPage implements AdminPage
{
    public function slug(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return __('Stock', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'stock';
    }

    public function menuOrder(): int
    {
        return 72;
    }
}
