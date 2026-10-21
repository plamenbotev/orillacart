<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class GiftCardsPage implements AdminPage
{
    public function slug(): string
    {
        return 'gift-cards';
    }

    public function title(): string
    {
        return __('Gift cards', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'gift-cards';
    }

    public function menuOrder(): int
    {
        return 27;
    }
}
