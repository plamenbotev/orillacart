<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class AttributeSetsPage implements AdminPage
{
    public function slug(): string
    {
        return 'attribute-sets';
    }

    public function title(): string
    {
        return __('Attribute sets', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'attribute-sets';
    }

    public function menuOrder(): int
    {
        return 60;
    }
}
