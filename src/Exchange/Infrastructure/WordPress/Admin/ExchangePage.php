<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

/**
 * Shop → Import and export: the products file out and back in.
 */
final readonly class ExchangePage implements AdminPage
{
    public function slug(): string
    {
        return 'exchange';
    }

    public function title(): string
    {
        return __('Import and export', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'exchange';
    }

    public function menuOrder(): int
    {
        return 74;
    }
}
