<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;

final readonly class StatusPage implements AdminPage
{
    public function slug(): string
    {
        return 'status';
    }

    public function title(): string
    {
        return __('Status', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'status';
    }

    public function group(): MenuGroup
    {
        return MenuGroup::System;
    }

    public function menuOrder(): int
    {
        return 1;
    }
}
