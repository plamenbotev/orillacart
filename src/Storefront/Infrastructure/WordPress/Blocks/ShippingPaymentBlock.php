<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Payment\Application\PaymentMethodService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Dto\RangeView;
use OrillaCart\Translations\Application\ContentTranslations;

/**
 * Shipping and payment as the shop has them now: each way to ship, where it goes and what it costs (by weight,
 * free from an amount), the places to collect, and the ways to pay with their fees. It follows the shop's
 * settings, so the page never says other than checkout does.
 */
final readonly class ShippingPaymentBlock implements BlockDefinition
{
    public function __construct(
        private CarrierService $carriers,
        private PaymentMethodService $payments,
        private GeographyService $geography,
        private SettingsService $settings,
        private TemplateRenderer $templates,
        private ContentTranslations $translations,
    ) {}

    public static function name(): string
    {
        return 'orillacart/shipping-payment';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $names = [];
        foreach ($this->geography->countries() as $country) {
            $names[$country->code] = $country->name;
        }
        $place = static fn(string $region): string => $names[explode('-', $region)[0]] ?? $region;
        $shop = $this->settings->current();
        // The shop's words in the page's language, where the shop translated them.
        $locale = $this->translations->shownLocale();
        $carriers = [];
        foreach ($this->carriers->carriers() as $carrier) {
            if (!$carrier->active) {
                continue;
            }
            $carriers[] = [
                'name' => self::in($locale, $carrier->translations, $carrier->name),
                'by_courier' => $carrier->method !== null,
                'rates' => array_map(static fn($rate): array => [
                    'name' => self::in($locale, $rate->translations, $rate->name),
                    'price' => $rate->price,
                    'per_item' => $rate->perItem,
                    'to' => array_map($place, $rate->regions),
                    'except' => array_map($place, $rate->excludedRegions),
                    'weight' => self::range($rate->weight),
                    'subtotal' => self::range($rate->subtotal),
                ], $carrier->rates),
                'pickup' => array_map(static fn($place): array => ['name' => self::in($locale, $place->translations['name'] ?? [], $place->name), 'address' => self::in($locale, $place->translations['address'] ?? [], $place->address), 'hours' => self::in($locale, $place->translations['hours'] ?? [], $place->hours), 'fee' => $place->fee], $carrier->pickupLocations),
            ];
        }
        $payments = [];
        foreach ($this->payments->methods() as $method) {
            if ($method->active) {
                $payments[] = ['name' => self::in($locale, $method->translations['name'] ?? [], $method->name), 'fee' => $method->fee, 'fee_percent' => $method->feePercent, 'to' => array_map($place, $method->countries)];
            }
        }

        return $this->templates->render('storefront/shipping-payment.html.twig', [
            'carriers' => $carriers,
            'payments' => $payments,
            'currency' => $shop->currency,
            'weight_unit' => $shop->weightUnit,
        ]);
    }

    /**
     * @param array<string, string> $translations by locale
     */
    private static function in(?string $locale, array $translations, string $own): string
    {
        return $locale === null ? $own : ($translations[$locale] ?? $own);
    }

    /** @return array{min: ?string, max: ?string}|null null for a range that holds everything */
    private static function range(?RangeView $range): ?array
    {
        if ($range === null) {
            return null;
        }
        $short = static fn(string $value): string => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        $min = \in_array($short($range->min), ['', '0'], true) ? null : $short($range->min);
        $max = $range->max === null ? null : $short($range->max);

        return $min === null && $max === null ? null : ['min' => $min, 'max' => $max];
    }
}
