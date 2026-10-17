<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Settings\Application\SettingsService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What templates need from WordPress and the shop: translation in the plugin's text domain, amounts in the
 * shop's currency with its decimals, and dates in the site's time zone and format.
 *
 *     {{ __('Order #%s')|format(order.number) }}   {{ order.total|money(order.currency) }}   {{ order.placed_at|shop_date }}
 */
final class ShopExtension extends AbstractExtension
{
    public function __construct(private readonly SettingsService $settings) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('__', static fn(string $text): string => \function_exists('__') ? __($text, 'orillacart') : $text),
            // {{ _n('%d item', '%d items', count)|format(count) }}
            new TwigFunction('_n', static fn(string $single, string $plural, int $number): string => \function_exists('_n')
                ? _n($single, $plural, $number, 'orillacart')
                : ($number === 1 ? $single : $plural)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('shop_date', $this->date(...)),
        ];
    }

    /**
     * A moment (as the API sends it) in the site's time zone and date format, with the time unless $withTime is false.
     */
    public function date(?string $moment, bool $withTime = true): string
    {
        $time = $moment === null ? false : strtotime($moment);
        if ($time === false) {
            return '';
        }
        if (!\function_exists('wp_date')) {
            return gmdate($withTime ? 'Y-m-d H:i' : 'Y-m-d', $time);
        }
        $format = get_option('date_format');
        $format = \is_string($format) && $format !== '' ? $format : 'Y-m-d';
        if ($withTime) {
            $clock = get_option('time_format');
            $format .= ' ' . (\is_string($clock) && $clock !== '' ? $clock : 'H:i');
        }

        return (string) wp_date($format, $time);
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
