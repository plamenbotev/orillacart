<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/**
 * Shop → Marketing → Automatic discounts: discounts on the order that need no code. They are coupons that apply
 * by themselves, so the price rules' script serves the screen.
 */
final readonly class AutomaticDiscountsPage implements AdminPage
{
    public const string SLUG = 'automatic-discounts';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function title(): string
    {
        return __('Automatic discounts', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'price-rules';
    }

    public function group(): MenuGroup
    {
        return MenuGroup::Marketing;
    }

    public function menuOrder(): int
    {
        return 2;
    }
}
