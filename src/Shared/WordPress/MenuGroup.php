<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * The Shop menu's entries (design 52): each AdminPage joins one, and a group with several pages shows them as
 * tabs. Hidden pages have no entry and are reached by their links, such as the setup.
 */
enum MenuGroup: string
{
    case Home = 'home';
    case Orders = 'orders';
    case Customers = 'customers';
    case Catalog = 'catalog';
    case Marketing = 'marketing';
    case Reports = 'reports';
    case Settings = 'settings';
    case System = 'system';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Home => __('Home', 'orillacart'),
            self::Orders => __('Orders', 'orillacart'),
            self::Customers => __('Customers', 'orillacart'),
            self::Catalog => __('Catalog', 'orillacart'),
            self::Marketing => __('Marketing', 'orillacart'),
            self::Reports => __('Reports', 'orillacart'),
            self::Settings => __('Settings', 'orillacart'),
            self::System => __('System', 'orillacart'),
            self::Hidden => '',
        };
    }

    /** Where in the menu; Hidden last, and never shown. */
    public function order(): int
    {
        return match ($this) {
            self::Home => 0,
            self::Orders => 10,
            self::Customers => 20,
            self::Catalog => 30,
            self::Marketing => 40,
            self::Reports => 50,
            self::Settings => 60,
            self::System => 70,
            self::Hidden => 1000,
        };
    }
}
