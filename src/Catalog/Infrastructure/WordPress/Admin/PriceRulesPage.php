<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class PriceRulesPage implements AdminPage
{
    public function slug(): string
    {
        return 'price-rules';
    }

    public function title(): string
    {
        return __('Price rules', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'price-rules';
    }

    public function menuOrder(): int
    {
        return 62;
    }
}
