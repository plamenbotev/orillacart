<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class CouponsPage implements AdminPage
{
    public function slug(): string
    {
        return 'coupons';
    }

    public function title(): string
    {
        return __('Coupons', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'coupons';
    }

    public function menuOrder(): int
    {
        return 25;
    }
}
