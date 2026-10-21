<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class IntegrationsPage implements AdminPage
{
    public function slug(): string
    {
        return 'integrations';
    }

    public function title(): string
    {
        return __('Integrations', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'integrations';
    }

    public function menuOrder(): int
    {
        return 97;
    }
}
