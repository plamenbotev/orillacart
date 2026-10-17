<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Settings\Application\SettingsService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What templates need from WordPress and the shop: translation in the plugin's text domain, and amounts in the
 * shop's currency with its decimals.
 *
 *     {{ __('Order #%s')|format(order.number) }}   {{ order.total|money(order.currency) }}
 */
final class ShopExtension extends AbstractExtension
{
    public function __construct(private readonly SettingsService $settings) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('__', static fn(string $text): string => \function_exists('__') ? __($text, 'orillacart') : $text)];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('money', $this->money(...))];
    }

    public function money(string $amount, ?string $currency = null): string
    {
        $settings = $this->settings->current();
        $number = \function_exists('number_format_i18n')
            ? number_format_i18n((float) $amount, $settings->priceDecimals)
            : number_format((float) $amount, $settings->priceDecimals);

        return $number . ' ' . ($currency ?? $settings->currency);
    }
}
