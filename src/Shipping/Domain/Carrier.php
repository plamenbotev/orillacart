<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A company or service that ships orders, such as "Courier". Its prices come from its own rates, in the order
 * checkout lists them, or from a shipping method an add-on brings (the courier's own services), set up with
 * the method's settings; or it is the shop's own pickup, offering the places where customers collect their
 * orders. An inactive carrier keeps everything but checkout offers none of it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carriers')]
class Carrier
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private bool $active;

    #[ORM\Column]
    private int $position;

    /** The add-on's shipping method that prices the carrier's services; null for its own rates. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $method = null;

    /** @var array<string, string|bool> */
    #[ORM\Column(type: 'sealed_json')]
    private array $methodSettings = [];

    /** @var list<array{id: string, name: string, address: string, hours: string, fee: string}> where customers collect orders, for the shop's own pickup */
    #[ORM\Column(type: 'json')]
    private array $pickupLocations = [];

    /** @var array{weekdays: list<int>, lead_days: int, cutoff: ?string, days_ahead: int, closed: list<string>, slots: list<array{id: string, from: string, to: string, limit: ?int}>, required: bool}|null the days the customer may choose for delivery; null for none */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $deliverySchedule = null;

    /** How many working days it takes to deliver, at least and at most; null for nothing said. */
    #[ORM\Column(nullable: true)]
    private ?int $deliveryMinDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $deliveryMaxDays = null;

    /** Whether the method's prices (or the pickup fees) are taxed, and by which tax group (null: the shop's default). */
    #[ORM\Column]
    private bool $methodTaxable = true;

    #[ORM\Column(nullable: true)]
    private ?int $methodTaxGroupId = null;

    /**
     * Goods of a class one of its rates lists go only by the rates that list it: the "Bulky" rate's goods not by
     * the standard rate that carries every item too. Off, a rate that carries every item carries them as well.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $classesKeptToTheirRates = false;

    /** @var Collection<int, ShippingRate> */
    #[ORM\OneToMany(targetEntity: ShippingRate::class, mappedBy: 'carrier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $rates;

    public function __construct(string $name, bool $active = true, int $position = 0)
    {
        $this->rates = new ArrayCollection();
        $this->rename($name);
        $this->active = $active;
        $this->position = $position;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The carrier has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ShippingRuleViolation('A carrier needs a name of up to %d characters.', 'orillacart_invalid_carrier_name', values: [self::MAX_NAME_LENGTH]);
        }
        $this->name = $name;
    }

    public function activate(bool $active): void
    {
        $this->active = $active;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function keepsClassesToTheirRates(): bool
    {
        return $this->classesKeptToTheirRates;
    }

    public function keepClassesToTheirRates(bool $kept): void
    {
        $this->classesKeptToTheirRates = $kept;
    }

    /**
     * The shipping classes its rates list.
     *
     * @return list<int>
     */
    public function namedClasses(): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn(ShippingRate $rate): array => $rate->details()->classes, $this->rates->toArray()))));
    }

    public function method(): ?string
    {
        return $this->method;
    }

    /** @return array<string, string|bool> */
    public function methodSettings(): array
    {
        return $this->methodSettings;
    }

    public function isMethodTaxable(): bool
    {
        return $this->methodTaxable;
    }

    public function methodTaxGroupId(): ?int
    {
        return $this->methodTaxGroupId;
    }

    /**
     * Prices the carrier's services with a shipping method, or (null) with its own rates again. A carrier that
     * uses a method has no rates of its own.
     *
     * @param array<string, string|bool> $settings
     *
     * @throws ShippingRuleViolation for a malformed method id
     */
    public function useMethod(?string $method, array $settings = [], bool $taxable = true, ?int $taxGroupId = null): void
    {
        if ($method !== null && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $method) !== 1) {
            throw new ShippingRuleViolation('"%s" is not a shipping method.', 'orillacart_unknown_shipping_method', values: [$method]);
        }
        $this->method = $method;
        $this->methodSettings = $method === null ? [] : $settings;
        $this->methodTaxable = $taxable;
        $this->methodTaxGroupId = $method === null ? null : $taxGroupId;
        if ($method !== null) {
            $this->rates->clear();
            $this->pickupLocations = [];
        }
    }

    /**
     * Makes it the shop's own pickup at these places, or (none) a carrier that ships again. A pickup has no rates
     * and no method; its fees are taxed as the method's prices would be.
     *
     * @param list<PickupLocation> $locations in the order checkout lists them
     *
     * @throws ShippingRuleViolation for a place listed twice
     */
    public function offerPickup(array $locations, bool $taxable = true, ?int $taxGroupId = null): void
    {
        $seen = [];
        foreach ($locations as $location) {
            if (isset($seen[$location->id])) {
                throw new ShippingRuleViolation('The pickup place "%s" is listed twice.', 'orillacart_duplicate_pickup_location', values: [$location->id]);
            }
            $seen[$location->id] = true;
        }
        $this->pickupLocations = array_map(static fn(PickupLocation $location): array => ['id' => $location->id, 'name' => $location->name, 'address' => $location->address, 'hours' => $location->hours, 'fee' => $location->fee->toFixed()], $locations);
        if ($locations !== []) {
            $this->useMethod(null);
            $this->rates->clear();
            $this->methodTaxable = $taxable;
            $this->methodTaxGroupId = $taxGroupId;
        }
    }

    /** The days, and times of day, the customer chooses for delivery; null to leave them out. */
    public function scheduleDeliveries(?DeliverySchedule $schedule): void
    {
        $this->deliverySchedule = $schedule?->toArray();
    }

    public function deliverySchedule(): ?DeliverySchedule
    {
        return $this->deliverySchedule === null ? null : DeliverySchedule::fromArray($this->deliverySchedule);
    }

    /** How many working days it takes to deliver; null to say nothing. */
    public function takeDeliveryDays(?DeliveryWindow $window): void
    {
        $this->deliveryMinDays = $window?->minDays;
        $this->deliveryMaxDays = $window?->maxDays;
    }

    public function deliveryWindow(): ?DeliveryWindow
    {
        return $this->deliveryMinDays === null || $this->deliveryMaxDays === null ? null : new DeliveryWindow($this->deliveryMinDays, $this->deliveryMaxDays);
    }

    /**
     * When an order placed now would arrive: by its delivery schedule, else by its working days; null when it says
     * neither, or the schedule has no day ahead.
     *
     * @param \DateTimeImmutable $now in the shop's time zone
     */
    public function deliveryEstimate(\DateTimeImmutable $now): ?DeliveryEstimate
    {
        $schedule = $this->deliverySchedule();

        return $schedule === null ? $this->deliveryWindow()?->estimate($now) : $schedule->estimate($now);
    }

    /** Whether it is the shop's own pickup. */
    public function isPickup(): bool
    {
        return $this->pickupLocations !== [];
    }

    /** @return list<PickupLocation> */
    public function pickupLocations(): array
    {
        return array_map(static fn(array $row): PickupLocation => new PickupLocation($row['id'], $row['name'], $row['address'], $row['hours'], Money::of($row['fee'])), $this->pickupLocations);
    }

    public function pickupLocation(string $id): ?PickupLocation
    {
        foreach ($this->pickupLocations() as $location) {
            if ($location->id === $id) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @return list<ShippingRate> in the order checkout lists them
     */
    public function rates(): array
    {
        $rates = $this->rates->toArray();
        usort($rates, static fn(ShippingRate $a, ShippingRate $b): int => $a->position() <=> $b->position());

        return $rates;
    }

    /**
     * Replaces the whole list of rates: drafts with an id change that rate, the others are new, and rates
     * missing from the list are removed.
     *
     * @param list<RateDraft> $drafts in the order checkout lists them
     *
     * @throws ShippingRuleViolation for a rate id that is not this carrier's, or rates for a carrier a method prices
     */
    public function replaceRates(array $drafts): void
    {
        if (($this->method !== null || $this->isPickup()) && $drafts !== []) {
            throw new ShippingRuleViolation('A carrier whose shipping method prices it, or the shop\'s own pickup, has no rates of its own.', 'orillacart_method_carrier_rates');
        }
        $existing = [];
        foreach ($this->rates as $rate) {
            if ($rate->isSaved()) {
                $existing[$rate->id()] = $rate;
            }
        }
        $kept = [];
        foreach ($drafts as $position => $draft) {
            if ($draft->id === null) {
                $this->rates->add(new ShippingRate($this, $draft->details, $position));
                continue;
            }
            $rate = $existing[$draft->id] ?? throw new ShippingRuleViolation('Shipping rate %d is not one of this carrier\'s rates.', 'orillacart_unknown_rate', values: [$draft->id]);
            if (isset($kept[$draft->id])) {
                throw new ShippingRuleViolation('Shipping rate %d is listed twice.', 'orillacart_duplicate_rate', values: [$draft->id]);
            }
            $rate->change($draft->details, $position);
            $kept[$draft->id] = true;
        }
        foreach ($existing as $id => $rate) {
            if (!isset($kept[$id])) {
                $this->rates->removeElement($rate);
            }
        }
    }

    /** The shop measures in other units now: each rate's ranges in them. */
    public function measuredIn(float $weightFactor, float $lengthFactor): void
    {
        foreach ($this->rates() as $rate) {
            $rate->measuredIn($weightFactor, $lengthFactor);
        }
    }
}
