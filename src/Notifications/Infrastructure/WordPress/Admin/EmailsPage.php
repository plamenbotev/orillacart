<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class EmailsPage implements AdminPage
{
    public function slug(): string
    {
        return 'emails';
    }

    public function title(): string
    {
        return __('Emails', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'emails';
    }

    public function menuOrder(): int
    {
        return 95;
    }
}
