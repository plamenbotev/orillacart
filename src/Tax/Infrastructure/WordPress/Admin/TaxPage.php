<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class TaxPage implements AdminPage
{
    public function slug(): string
    {
        return 'tax';
    }

    public function title(): string
    {
        return __('Tax', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'tax';
    }

    public function menuOrder(): int
    {
        return 50;
    }
}
