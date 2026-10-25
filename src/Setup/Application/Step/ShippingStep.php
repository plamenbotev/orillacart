<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\Dto\ShippingAnswers;
use OrillaCart\Setup\Application\Dto\ZoneShipping;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Setup\Domain\Regions;
use OrillaCart\Setup\Domain\ShippingZone;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shipping\Application\CarrierService;
use OrillaCart\Shipping\Application\Dto\CarrierView;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;

/**
 * Shipping: one way to ship, priced for each zone the shop sells to (one price, free from an amount, or by
 * weight), and a place customers collect orders. A zone with no shipping is one whose customers cannot order.
 */
final readonly class ShippingStep implements SetupStep
{
    public const string KEY = 'shipping';
    private const string CARRIER = 'shipping.carrier';
    private const string PICKUP = 'shipping.pickup';
    /** The id of the place to collect from that the setup makes. */
    private const string PICKUP_ID = 'shop';

    public function __construct(
        private CarrierService $carriers,
        private GeographyService $geography,
        private SettingsService $settings,
        private ShopFacts $facts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 40;
    }

    public function answers(): string
    {
        return ShippingAnswers::class;
    }

    /**
     * The zones the shop ships to, from where it sells.
     *
     * @return list<ShippingZone>
     */
    public function zones(SetupAnswers $answers): array
    {
        $home = $this->facts->country($answers);
        if ($home === null) {
            return [];
        }
        $selling = $this->facts->selling($answers);
        $eu = array_values(array_map(static fn($country): string => $country->code, array_filter($this->geography->countries(), static fn($country): bool => $country->inEu)));

        return ShippingZone::of($home, Regions::from($selling->regions), $eu, $selling->countries);
    }

    public function plan(SetupAnswers $answers): array
    {
        $shipping = $answers->of(self::KEY, ShippingAnswers::class);
        if ($shipping === null) {
            return [];
        }
        $changes = [];
        $carrier = $this->carrier($shipping, $this->zones($answers));
        $existing = $this->named($carrier->name);
        if ($carrier->rates !== [] && ($existing === null || self::rates($existing) !== self::sent($carrier->rates))) {
            $changes[] = new PlannedChange(
                self::CARRIER,
                self::KEY,
                Text::of('Checkout offers "%s":', $carrier->name),
                $existing === null ? null : Text::of('The shop ships "%s" already; replacing it gives it these prices.', $carrier->name),
                ['rates' => self::sent($carrier->rates)],
                $this->unshipped($shipping, $this->zones($answers)),
                $this->details($shipping, $this->zones($answers)),
            );
        }
        if ($shipping->pickup !== null) {
            $pickup = $shipping->pickup;
            $existing = $this->named($pickup->name);
            $location = [self::PICKUP_ID, trim($pickup->name), trim($pickup->address), trim($pickup->hours), self::amount($pickup->fee)];
            if ($existing === null || array_map(static fn($place): array => [$place->id, $place->name, $place->address, $place->hours, self::amount($place->fee)], $existing->pickupLocations) !== [$location]) {
                $changes[] = new PlannedChange(
                    self::PICKUP,
                    self::KEY,
                    Text::of('Customers can collect their orders at %1$s, %2$s.', trim($pickup->name), trim($pickup->address)),
                    $existing === null ? null : Text::of('The shop has a place to collect called "%s" already.', $pickup->name),
                    ['location' => $location],
                );
            }
        }

        return $changes;
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $shipping = $answers->of(self::KEY, ShippingAnswers::class);
        if ($shipping === null) {
            return;
        }
        $carrier = match ($change->key) {
            self::CARRIER => $this->carrier($shipping, $this->zones($answers)),
            self::PICKUP => $shipping->pickup === null ? null : new SaveCarrier(trim($shipping->pickup->name), pickupLocations: [
                new SavePickupLocation(self::PICKUP_ID, trim($shipping->pickup->name), trim($shipping->pickup->address), trim($shipping->pickup->hours), $shipping->pickup->fee),
            ]),
            default => null,
        };
        if ($carrier === null) {
            return;
        }
        $existing = $this->named($carrier->name);
        $existing === null ? $this->carriers->create($carrier) : $this->carriers->update($existing->id, $carrier);
    }

    /** @param list<ShippingZone> $zones */
    private function carrier(ShippingAnswers $shipping, array $zones): SaveCarrier
    {
        $name = trim($shipping->name);
        $rates = [];
        foreach ($zones as $zone) {
            $priced = $shipping->zone($zone->id);
            if ($priced === null) {
                continue;
            }
            $to = static fn(string $price, ?SaveRange $weight = null, ?SaveRange $subtotal = null): SaveShippingRate => new SaveShippingRate($name, $price, regions: $zone->countries, weight: $weight, subtotal: $subtotal, excludedRegions: $zone->excluded);
            switch ($priced->prices) {
                case ZoneShipping::FLAT:
                    $rates[] = $to($priced->price);
                    break;
                case ZoneShipping::FREE_ABOVE:
                    $free = $priced->freeAbove ?? '0';
                    $rates[] = $to($priced->price, subtotal: new SaveRange('0', self::below($free)));
                    $rates[] = $to('0', subtotal: new SaveRange($free));
                    break;
                case ZoneShipping::BY_WEIGHT:
                    $weights = $priced->weights;
                    usort($weights, static fn($a, $b): int => Money::of($a->upTo)->compare(Money::of($b->upTo)));
                    $from = '0';
                    foreach ($weights as $band) {
                        $rates[] = $to($band->price, new SaveRange($from, $band->upTo));
                        $from = self::above($band->upTo);
                    }
                    break;
            }
        }

        return new SaveCarrier($name, rates: $rates);
    }

    /**
     * The zones' prices in words, a line each.
     *
     * @param list<ShippingZone> $zones
     *
     * @return list<Text>
     */
    private function details(ShippingAnswers $shipping, array $zones): array
    {
        $currency = $this->settings->current()->currency;
        $lines = [];
        foreach ($zones as $zone) {
            $priced = $shipping->zone($zone->id);
            if ($priced === null || $priced->prices === ZoneShipping::NONE) {
                continue;
            }
            $where = $this->where($zone);
            $lines[] = match ($priced->prices) {
                ZoneShipping::FREE_ABOVE => Text::of('%1$s: %2$s %3$s, free from %4$s %3$s.', $where, $priced->price, $currency, $priced->freeAbove ?? '0'),
                ZoneShipping::BY_WEIGHT => Text::of('%1$s: by weight, %2$s.', $where, implode(', ', array_map(static fn($band): string => "≤ {$band->upTo}: {$band->price} {$currency}", $priced->weights))),
                default => Text::of('%1$s: %2$s %3$s.', $where, $priced->price, $currency),
            };
        }

        return $lines;
    }

    /** @param list<ShippingZone> $zones */
    private function unshipped(ShippingAnswers $shipping, array $zones): ?Text
    {
        $missing = [];
        foreach ($zones as $zone) {
            $priced = $shipping->zone($zone->id);
            if ($priced === null || $priced->prices === ZoneShipping::NONE) {
                $missing[] = $this->where($zone);
            }
        }

        return $missing === [] || $shipping->pickup !== null ? null : Text::of('Nothing ships to %s, so customers there cannot order: give it a price, or sell only where the shop ships.', implode('; ', array_map(strval(...), $missing)));
    }

    private function where(ShippingZone $zone): Text
    {
        if ($zone->id === ShippingZone::HOME) {
            foreach ($this->geography->countries() as $country) {
                if ($country->code === ($zone->countries[0] ?? null)) {
                    return Text::of('%s', $country->name);
                }
            }
        }

        return match ($zone->id) {
            ShippingZone::EU => Text::of('the rest of the EU'),
            ShippingZone::EUROPE => Text::of('the rest of Europe'),
            ShippingZone::WORLD => Text::of('the rest of the world'),
            default => Text::of('the other countries'),
        };
    }

    private function named(string $name): ?CarrierView
    {
        foreach ($this->carriers->carriers() as $carrier) {
            if ($carrier->name === trim($name)) {
                return $carrier;
            }
        }

        return null;
    }

    /** @return list<array<array-key, mixed>> */
    private static function rates(CarrierView $carrier): array
    {
        return array_map(static fn($rate): array => [$rate->name, self::amount($rate->price), $rate->regions, $rate->excludedRegions, self::range($rate->weight?->min, $rate->weight?->max), self::range($rate->subtotal?->min, $rate->subtotal?->max)], $carrier->rates);
    }

    /**
     * @param list<SaveShippingRate> $rates
     *
     * @return list<array<array-key, mixed>>
     */
    private static function sent(array $rates): array
    {
        return array_map(static fn(SaveShippingRate $rate): array => [$rate->name, self::amount($rate->price), $rate->regions, $rate->excludedRegions, self::range($rate->weight?->min, $rate->weight?->max), self::range($rate->subtotal?->min, $rate->subtotal?->max)], $rates);
    }

    private static function amount(string $amount): string
    {
        return Money::of($amount)->toFixed();
    }

    /** @return array{string, ?string}|null null for the range that holds everything */
    private static function range(?string $min, ?string $max): ?array
    {
        $min = self::amount($min ?? '0');
        $max = $max === null ? null : self::amount($max);

        return $max === null && $min === self::amount('0') ? null : [$min, $max];
    }

    /** The amount just below, the most a range up to it holds: ranges hold both their ends. */
    private static function below(string $amount): string
    {
        return Money::of($amount)->subtract(Money::fromTenThousandths(1))->atLeastZero()->toFixed();
    }

    private static function above(string $amount): string
    {
        return Money::of($amount)->add(Money::fromTenThousandths(1))->toFixed();
    }
}
