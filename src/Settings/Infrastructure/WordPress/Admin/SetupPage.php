<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress\Admin;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AdminPage;

/** Shop → Setup: where the shop sells from, how it is paid and ships, and its first products, in a few steps. */
final readonly class SetupPage implements AdminPage
{
    public const string SLUG = 'setup';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function title(): string
    {
        return __('Setup', 'orillacart');
    }

    public function capability(): string
    {
        return Capability::MANAGE_SHOP;
    }

    public function script(): string
    {
        return 'setup';
    }

    public function menuOrder(): int
    {
        return 100;
    }
}
