<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class JobsPage implements AdminPage
{
    public function slug(): string
    {
        return 'jobs';
    }

    public function title(): string
    {
        return __('Background jobs', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'jobs';
    }

    public function menuOrder(): int
    {
        return 98;
    }
}
