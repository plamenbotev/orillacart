<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Geography\Domain\AddressFormat;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tax\Application\TaxCalculator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What templates need from WordPress and the shop: translation in the plugin's text domain, amounts in the
 * shop's currency with its decimals, dates in the site's time zone and format, addresses in their country's
 * layout, and the slots add-ons print into.
 *
 *     {{ __('Order #%s')|format(order.number) }}   {{ order.total|money(order.currency) }}   {{ order.placed_at|shop_date }}
 *     {{ order.billing|address_lines|join('<br>') }}   {{ slot('receipt_after', order.number) }}
 */
final class ShopExtension extends AbstractExtension
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly GeographyService $geography,
        private readonly ?TaxCalculator $taxes = null,
        private readonly ?Currencies $currencies = null,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('__', static fn(string $text): string => \function_exists('__') ? __($text, 'orillacart') : $text),
            // {{ _n('%d item', '%d items', count)|format(count) }}
            new TwigFunction('_n', static fn(string $single, string $plural, int $number): string => \function_exists('_n')
                ? _n($single, $plural, $number, 'orillacart')
                : ($number === 1 ? $single : $plural)),
            // {{ slot('product_after_form', product.id) }}: what add-ons print there (docs/extensions.md lists them)
            // Whether shop prices include tax, for the "incl. tax" next to them.
            new TwigFunction('prices_include_tax', fn(): bool => $this->taxes?->pricesIncludeTax() ?? false),
            new TwigFunction('slot', $this->slot(...), ['is_safe' => ['html'], 'is_variadic' => true]),
            // The currency switcher: {% for code in shop_currencies() %}, and the one the shopper is in.
            new TwigFunction('shop_currencies', fn(): array => $this->currencies?->codes() ?? [$this->settings->current()->currency]),
            new TwigFunction('shopper_currency', fn(): string => $this->currencies?->current() ?? $this->settings->current()->currency),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('shop_date', $this->date(...)),
            // {{ order.delivery_date|shop_day }}: a day of the calendar, "Monday, 19 October 2026"
            new TwigFilter('shop_day', $this->day(...)),
            // {% for line in order.billing|address_lines %}
            new TwigFilter('address_lines', $this->addressLines(...)),
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

    /**
     * A day of the calendar ("2026-10-19") with its weekday, in the site's language and date format: a day, not a
     * moment, so no time zone moves it.
     */
    public function day(?string $date): string
    {
        $day = $date === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($day === false) {
            return '';
        }
        if (!\function_exists('wp_date')) {
            return $day->format('l, Y-m-d');
        }
        $format = get_option('date_format');

        return (string) wp_date('l, ' . (\is_string($format) && $format !== '' ? $format : 'Y-m-d'), $day->getTimestamp(), new \DateTimeZone('UTC'));
    }

    /**
     * An amount in the shop's number format, with the currency's sign (or code) where the shop puts it. An amount
     * in another currency, such as an old order's, shows that currency's code.
     */
    /**
     * An amount in its currency: {{ order.total|money(order.currency) }}. Without a currency it is one of the shop's
     * prices, shown in the currency the shopper chose, converted as the shop set: {{ product.price|money }}.
     */
    public function money(string $amount, ?string $currency = null): string
    {
        $settings = $this->settings->current();
        $value = Money::of($amount);
        if ($currency === null && $this->currencies !== null) {
            $currency = $this->currencies->current();
            $value = $this->currencies->price($value, $currency);
        }
        $decimals = $currency === null || $currency === $settings->currency ? $settings->priceDecimals : new Currency($currency)->decimals();
        // Exact to the last decimal, never through a float; WordPress's locale chooses the separators.
        $fixed = $value->format($decimals);
        [$whole, $fraction] = explode('.', $fixed . '.');
        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');
        $locale = $GLOBALS['wp_locale'] ?? null;
        $format = $locale instanceof \WP_Locale ? $locale->number_format : [];
        $thousands = \is_string($format['thousands_sep'] ?? null) ? $format['thousands_sep'] : ',';
        $point = \is_string($format['decimal_point'] ?? null) ? $format['decimal_point'] : '.';
        $number = ($negative ? '-' : '') . strrev(implode(strrev($thousands), str_split(strrev($whole), 3))) . ($fraction === '' ? '' : $point . $fraction);
        $sign = $currency !== null && $currency !== $settings->currency
            ? $currency
            : ($settings->currencySign !== '' ? $settings->currencySign : $settings->currency);

        return CurrencyPosition::from($settings->currencyPosition)->place($number, $sign);
    }

    /**
     * Runs the action orillacart_<name> with the arguments and gives what its callbacks printed. Add-ons are
     * trusted code, like any plugin hooked into WordPress, so the output is not escaped.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function slot(string $name, array $arguments = []): string
    {
        if (!\function_exists('do_action')) {
            return '';
        }
        ob_start();
        try {
            do_action('orillacart_' . $name, ...array_values($arguments));
        } finally {
            $printed = (string) ob_get_clean();
        }

        return $printed;
    }

    /**
     * An address (as the API sends it) in the lines its country writes, with the country's and state's names.
     * The filter orillacart_address_format changes a country's layout.
     *
     * @param array<string, mixed>|object|null $address
     *
     * @return list<string>
     */
    public function addressLines(array|object|null $address): array
    {
        $parts = \is_object($address) ? get_object_vars($address) : ($address ?? []);
        $country = self::part($parts, 'country');
        $format = AddressFormat::of($country);
        if (\function_exists('apply_filters')) {
            $filtered = apply_filters('orillacart_address_format', $format, $country);
            $format = \is_string($filtered) && $filtered !== '' ? $filtered : $format;
        }
        $state = self::part($parts, 'state');

        return $this->geography->addressLines(
            self::part($parts, 'first_name', 'firstName'),
            self::part($parts, 'last_name', 'lastName'),
            self::part($parts, 'street'),
            self::part($parts, 'city'),
            self::part($parts, 'postcode'),
            $country,
            $state === '' ? null : $state,
            $format,
        );
    }

    /**
     * The first of the keys the address has, as text.
     *
     * @param array<array-key, mixed> $parts
     */
    private static function part(array $parts, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($parts[$key]) && \is_scalar($parts[$key])) {
                return trim((string) $parts[$key]);
            }
        }

        return '';
    }
}
