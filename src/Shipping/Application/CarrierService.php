<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\PostcodePattern;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\SettingValues;
use OrillaCart\Shipping\Application\Dto\CarrierView;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SavePickupLocation;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierNotFound;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\Measure;
use OrillaCart\Shipping\Domain\PickupLocation;
use OrillaCart\Shipping\Domain\Range;
use OrillaCart\Shipping\Domain\RateDetails;
use OrillaCart\Shipping\Domain\RateDraft;
use OrillaCart\Shipping\Domain\Region;
use OrillaCart\Shipping\Domain\ShippingClass;
use OrillaCart\Shipping\Domain\ShippingClassRepository;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tax\Application\TaxGroupService;

/**
 * Use cases for carriers and their rates, as the Shipping admin screen runs them.
 */
final readonly class CarrierService
{
    public function __construct(
        private CarrierRepository $carriers,
        private GeographyService $geography,
        private TaxGroupService $taxGroups,
        private ShippingClassRepository $classes,
        private ShippingMethods $methods,
    ) {}

    /**
     * @return list<CarrierView> in checkout order
     */
    public function carriers(): array
    {
        return array_map($this->view(...), $this->carriers->all());
    }

    /**
     * @throws CarrierNotFound
     */
    public function carrier(int $id): CarrierView
    {
        return $this->view($this->get($id));
    }

    /**
     * @throws ShippingRuleViolation
     */
    public function create(SaveCarrier $command): CarrierView
    {
        $carrier = new Carrier($command->name, $command->active, $command->position);
        $this->price($carrier, $command);
        $carrier->scheduleDeliveries($command->deliverySchedule?->toSchedule());
        $carrier->keepClassesToTheirRates($command->classesKeptToTheirRates);
        $this->carriers->save($carrier);

        return $this->view($carrier);
    }

    /**
     * @throws CarrierNotFound
     * @throws ShippingRuleViolation
     */
    public function update(int $id, SaveCarrier $command): CarrierView
    {
        $carrier = $this->get($id);
        $carrier->rename($command->name);
        $carrier->activate($command->active);
        $carrier->moveTo($command->position);
        $this->price($carrier, $command);
        $carrier->scheduleDeliveries($command->deliverySchedule?->toSchedule());
        $carrier->keepClassesToTheirRates($command->classesKeptToTheirRates);
        $this->carriers->save($carrier);

        return $this->view($carrier);
    }

    /**
     * Orders keep the carrier's and rate's names, so removing a carrier never changes a placed order.
     *
     * @throws CarrierNotFound
     */
    public function delete(int $id): void
    {
        $this->carriers->remove($this->get($id));
    }

    /**
     * The carrier's own rates, the method that prices it with its settings, or its pickup places.
     *
     * @throws ShippingRuleViolation
     * @throws RuleViolation         for settings the method does not declare, or of the wrong kind
     */
    private function price(Carrier $carrier, SaveCarrier $command): void
    {
        if ($command->pickupLocations !== []) {
            if ($command->method !== null || $command->rates !== []) {
                throw new ShippingRuleViolation('The shop\'s own pickup has no rates and no shipping method.', 'orillacart_method_carrier_rates');
            }
            $carrier->offerPickup(
                array_map(static fn(SavePickupLocation $location): PickupLocation => new PickupLocation($location->id, $location->name, $location->address, $location->hours, Money::of($location->fee)), $command->pickupLocations),
                $command->methodTaxable,
                $this->taxGroup($command->methodTaxGroupId),
            );

            return;
        }
        $carrier->offerPickup([]);
        if ($command->method === null) {
            $carrier->useMethod(null);
            $carrier->replaceRates($this->drafts($command->rates));

            return;
        }
        if ($command->rates !== []) {
            throw new ShippingRuleViolation('A carrier whose shipping method prices it has no rates of its own.', 'orillacart_method_carrier_rates');
        }
        $method = $this->methods->get($command->method);
        $saved = $carrier->method() === $command->method ? $carrier->methodSettings() : [];
        $settings = SettingValues::accept($method->settings(), $saved, $command->methodSettings, $method->name());
        $missing = $command->active ? SettingValues::missing($method->settings(), $settings) : null;
        if ($missing !== null) {
            throw new ShippingRuleViolation('%1$s needs "%2$s" before the carrier can be turned on.', 'orillacart_shipping_method_incomplete', values: [$method->name(), $missing->label]);
        }
        $carrier->useMethod($command->method, $settings, $command->methodTaxable, $this->taxGroup($command->methodTaxGroupId));
    }

    private function view(Carrier $carrier): CarrierView
    {
        $method = $carrier->method() === null ? null : $this->methods->find($carrier->method());

        return CarrierView::fromCarrier($carrier, $method?->settings() ?? []);
    }

    private function get(int $id): Carrier
    {
        return $this->carriers->find($id) ?? throw new CarrierNotFound($id);
    }

    /**
     * @param list<SaveShippingRate> $rates
     *
     * @return list<RateDraft>
     */
    private function drafts(array $rates): array
    {
        return array_map(fn(SaveShippingRate $rate): RateDraft => new RateDraft($rate->id, new RateDetails(
            $rate->name,
            Money::of($rate->price),
            $rate->perItem,
            array_map($this->region(...), $rate->regions),
            self::range($rate->weight),
            self::range($rate->volume),
            self::range($rate->length),
            self::range($rate->width),
            self::range($rate->height),
            self::range($rate->subtotal),
            $rate->taxable,
            $this->taxGroup($rate->taxGroupId),
            array_map($this->shippingClass(...), $rate->shippingClasses),
            array_map(PostcodePattern::of(...), $rate->postcodes),
            array_map($this->region(...), $rate->excludedRegions),
            array_map(PostcodePattern::of(...), $rate->excludedPostcodes),
            self::range($rate->items),
        )), $rates);
    }

    private function region(string $code): Region
    {
        $region = Region::of($code);
        $this->geography->require($region->country, $region->state);

        return $region;
    }

    private function taxGroup(?int $id): ?int
    {
        if ($id !== null && !$this->taxGroups->exists($id)) {
            throw new ShippingRuleViolation('There is no tax group %d.', 'orillacart_unknown_tax_group', values: [$id]);
        }

        return $id;
    }

    private function shippingClass(int $id): int
    {
        if ($id !== ShippingClass::NONE && $this->classes->find($id) === null) {
            throw new ShippingRuleViolation('There is no shipping class %d.', 'orillacart_unknown_shipping_class', values: [$id]);
        }

        return $id;
    }

    private static function range(?SaveRange $range): Range
    {
        return $range === null ? Range::any() : new Range(Measure::of($range->min), $range->max === null ? null : Measure::of($range->max));
    }

    /** The shop measures in other units now: every rate's ranges converted, so each takes the parcels it took. */
    public function convertMeasures(float $weightFactor, float $lengthFactor): void
    {
        foreach ($this->carriers->all() as $carrier) {
            $carrier->measuredIn($weightFactor, $lengthFactor);
            $this->carriers->save($carrier);
        }
    }
}
