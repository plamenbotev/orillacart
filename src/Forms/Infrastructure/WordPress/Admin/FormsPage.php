<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class FormsPage implements AdminPage
{
    public function slug(): string
    {
        return 'forms';
    }

    public function title(): string
    {
        return __('Forms', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'forms';
    }

    public function menuOrder(): int
    {
        return 61;
    }
}
