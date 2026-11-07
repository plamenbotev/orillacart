<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

/** Settings › Extensions: the extensions written in the script language (design 56). */
final readonly class ExtensionsPage implements AdminPage
{
    public function slug(): string
    {
        return 'extensions';
    }

    public function title(): string
    {
        return __('Extensions', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'extensions';
    }

    public function group(): MenuGroup
    {
        return MenuGroup::Settings;
    }

    public function menuOrder(): int
    {
        return 12;
    }
}
