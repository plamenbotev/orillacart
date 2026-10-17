<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;

/**
 * The data of legacy-checkout.json rebuilt in the new model, for one of the recorded shop settings.
 *
 * Mapping: the old global "vat" switch and the per-product and per-rate "vat" field (global | yes | no) become
 * each product's and rate's "taxable"; the old vatType becomes the tax basis (0 shipping address, 1 shop address,
 * 3 billing address). A rate's countries and states become regions ("US" + "CA" is "US-CA"); a range ending at 0
 * has no end; the old order of rates (priority, then price) becomes their position. The old payment classes
 * become gateways: payondelivery is cash_on_delivery, banktransfer bank_transfer.
 *
 * @phpstan-type MeasureRange array{float|int, float|int}
 * @phpstan-type RateFields array{name: string, value: float|int, priority?: int, countries?: list<string>, states?: list<string>, weight?: MeasureRange, volume?: MeasureRange, total?: MeasureRange, length?: MeasureRange, width?: MeasureRange, height?: MeasureRange, per_item?: string, vat?: string, tax_group?: string}
 * @phpstan-type Recorded array{tax_rates: list<array{string, string, string, string, string}>, products: array<string, array{string, string, string, ?string, float|int, float|int, float|int, float|int}>, mug_options: array<string, array{string, string, float|int}>, carriers: array<string, array{string, array<string, RateFields>}>, payments: array<string, array{string, int, list<string>}>, carts: array<string, list<array{string, int, list<string>}>>, customers: array<string, array{?string, ?string, ?string, ?string}>, settings: array<string, array{vat: bool, vatType: int}>, cases: list<array<string, mixed>>}
 */
final class LegacyCheckout
{
    public const array GATEWAYS = ['payondelivery' => 'cash_on_delivery', 'banktransfer' => 'bank_transfer', 'cheque' => 'cheque', 'paypal' => 'paypal'];

    public readonly TestShop $shop;
    /** @var array<int, string> rate id => recorded key */
    public array $rateKeys = [];
    /** @var array<string, int> */
    private array $productIds = [];
    /** @var array<string, int> */
    private array $optionIds = [];

    /**
     * @param Recorded $recorded
     */
    public function __construct(public readonly array $recorded, string $setting)
    {
        $this->shop = new TestShop();
        ['vat' => $vat, 'vatType' => $vatType] = $recorded['settings'][$setting];
        $taxable = static fn(string $field): bool => $field === 'yes' || ($field === 'global' && $vat);

        $groups = [];
        foreach ($recorded['tax_rates'] as [$group, $country, $state, $percent, $name]) {
            $groups[$group] ??= $this->shop->taxGroups->create(new SaveTaxGroup(ucfirst($group)))->id;
            $this->shop->taxGroups->addRate($groups[$group], new SaveTaxRate($country, $state === '' ? null : $state, $percent, $name));
        }
        $basis = match ($vatType) {
            0 => TaxBasis::ShippingAddress,
            1 => TaxBasis::ShopAddress,
            3 => TaxBasis::BillingAddress,
            default => throw new \LogicException("No tax basis for vatType {$vatType}"),
        };
        $this->shop->taxSettings->save(new TaxSettings($basis, $groups['standard']));

        $id = 100;
        foreach ($recorded['products'] as $key => [$type, $price, $productVat, $group, $weight, $length, $width, $height]) {
            $this->productIds[$key] = ++$id;
            $this->shop->content->add($id, $key);
            $measure = static fn(float|int $value): ?string => (float) $value === 0.0 ? null : self::decimal($value);
            $attributes = $key === 'mug' ? [new SaveAttribute('Finish', array_values(array_map(
                static fn(array $option): SaveOption => new SaveOption($option[0], $option[1] === '-' ? '-' . self::decimal($option[2]) : self::decimal($option[2])),
                $recorded['mug_options'],
            )))] : [];
            $saved = $this->shop->products->save($id, new SaveProduct(
                $price,
                $type === 'regular' ? 'physical' : 'virtual',
                taxable: $taxable($productVat),
                taxGroupId: $group === null ? null : $groups[$group],
                stockMode: 'always_available',
                weight: $measure($weight),
                length: $measure($length),
                width: $measure($width),
                height: $measure($height),
                attributes: $attributes,
            ));
            foreach (array_keys($key === 'mug' ? $recorded['mug_options'] : []) as $position => $optionKey) {
                $this->optionIds[$optionKey] = $saved->attributes[0]->options[$position]->id;
            }
        }

        $position = 0;
        foreach ($recorded['carriers'] as [$name, $rates]) {
            $ordered = $rates;
            uasort($ordered, static fn(array $a, array $b): int => [$a['priority'] ?? 0, $a['value']] <=> [$b['priority'] ?? 0, $b['value']]);
            $saves = [];
            foreach ($ordered as $rate) {
                $regions = [];
                foreach ($rate['countries'] ?? [] as $country) {
                    foreach ($rate['states'] ?? [null] as $state) {
                        $regions[] = $state === null ? $country : "{$country}-{$state}";
                    }
                }
                $saves[] = new SaveShippingRate(
                    $rate['name'],
                    self::decimal($rate['value']),
                    perItem: ($rate['per_item'] ?? 'no') === 'yes',
                    regions: $regions,
                    weight: self::range($rate['weight'] ?? null),
                    volume: self::range($rate['volume'] ?? null),
                    length: self::range($rate['length'] ?? null),
                    width: self::range($rate['width'] ?? null),
                    height: self::range($rate['height'] ?? null),
                    subtotal: self::range($rate['total'] ?? null),
                    taxable: $taxable($rate['vat'] ?? 'global'),
                    taxGroupId: isset($rate['tax_group']) ? $groups[$rate['tax_group']] : null,
                );
            }
            $carrier = $this->shop->carriers->create(new SaveCarrier($name, position: ++$position, rates: $saves));
            foreach (array_keys($ordered) as $index => $key) {
                $this->rateKeys[$carrier->rates[$index]->id] = $key;
            }
        }

        foreach ($recorded['payments'] as $class => [$label, $order, $countries]) {
            $this->shop->paymentMethods->update(self::GATEWAYS[$class], new SavePaymentMethod($label, true, $order, $countries, settings: $class === 'paypal' ? ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true] : []));
        }
    }

    public function summary(string $cart, string $customer): CheckoutView
    {
        $owner = new CartOwner(null);
        foreach ($this->recorded['carts'][$cart] as [$product, $quantity, $options]) {
            $result = $this->shop->carts->add($owner, new AddToCart($this->productIds[$product], array_map(fn(string $option): int => $this->optionIds[$option], $options), $quantity));
            $owner = new CartOwner($result->newToken ?? $owner->token);
        }
        [$shippingCountry, $shippingState, $billingCountry, $billingState] = $this->recorded['customers'][$customer];

        return $this->shop->checkout->summary($owner, new CheckoutDetails($shippingCountry, $shippingState, $billingCountry, $billingState));
    }

    /**
     * @param array{float|int, float|int}|null $range
     */
    private static function range(?array $range): ?SaveRange
    {
        return $range === null ? null : new SaveRange(self::decimal($range[0]), (float) $range[1] === 0.0 ? null : self::decimal($range[1]));
    }

    public static function decimal(float|int|string $value): string
    {
        return rtrim(rtrim(\sprintf('%.4F', (float) $value), '0'), '.');
    }
}
