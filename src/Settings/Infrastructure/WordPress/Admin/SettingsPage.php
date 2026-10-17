<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class SettingsPage implements AdminPage
{
    public function slug(): string
    {
        return 'settings';
    }

    public function title(): string
    {
        return __('Settings', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'settings';
    }

    public function menuOrder(): int
    {
        return 100;
    }
}
