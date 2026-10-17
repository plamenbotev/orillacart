<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

final readonly class PaymentsPage implements AdminPage
{
    public function slug(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return __('Payments', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'payments';
    }

    public function menuOrder(): int
    {
        return 90;
    }
}
