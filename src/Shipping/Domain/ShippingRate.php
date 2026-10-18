<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One way a carrier ships: where to, which parcels, and the price. Checkout offers it when the parcel's
 * destination is in one of its regions and matches one of its postcodes, it carries the shipping class of every item, and every measure of the parcel
 * is in its range.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipping_rates')]
class ShippingRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: RateDetails::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $price;

    #[ORM\Column]
    private bool $perItem;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $regions;

    #[ORM\Embedded(columnPrefix: 'weight_')]
    private Range $weight;

    #[ORM\Embedded(columnPrefix: 'volume_')]
    private Range $volume;

    #[ORM\Embedded(columnPrefix: 'length_')]
    private Range $length;

    #[ORM\Embedded(columnPrefix: 'width_')]
    private Range $width;

    #[ORM\Embedded(columnPrefix: 'height_')]
    private Range $height;

    #[ORM\Embedded(columnPrefix: 'subtotal_')]
    private Range $subtotal;

    #[ORM\Column]
    private bool $taxable;

    #[ORM\Column(nullable: true)]
    private ?int $taxGroupId;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $classes = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $postcodes = [];

    /**
     * Created through Carrier::replaceRates().
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Carrier::class, inversedBy: 'rates')]
        #[ORM\JoinColumn(name: 'carrier_id', nullable: false, onDelete: 'CASCADE')]
        private Carrier $carrier,
        RateDetails $details,
        #[ORM\Column]
        private int $position,
    ) {
        $this->change($details, $position);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The shipping rate has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function carrier(): Carrier
    {
        return $this->carrier;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function change(RateDetails $details, int $position): void
    {
        $this->name = trim($details->name);
        $this->price = $details->price->toFixed();
        $this->perItem = $details->perItem;
        $this->regions = array_values(array_map(static fn(Region $region): string => (string) $region, $details->regions));
        $this->weight = $details->weight;
        $this->volume = $details->volume;
        $this->length = $details->length;
        $this->width = $details->width;
        $this->height = $details->height;
        $this->subtotal = $details->subtotal;
        $this->taxable = $details->taxable;
        $this->taxGroupId = $details->taxGroupId;
        $this->classes = array_values($details->classes);
        $this->postcodes = array_values(array_map(static fn(PostcodePattern $pattern): string => (string) $pattern, $details->postcodes));
        $this->position = $position;
    }

    public function details(): RateDetails
    {
        return new RateDetails(
            $this->name,
            Money::of($this->price),
            $this->perItem,
            array_map(Region::of(...), $this->regions),
            $this->weight,
            $this->volume,
            $this->length,
            $this->width,
            $this->height,
            $this->subtotal,
            $this->taxable,
            $this->taxGroupId,
            $this->classes,
            array_map(PostcodePattern::of(...), $this->postcodes),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Whether the rate takes this parcel to this destination. Without a destination, only rates that ship
     * everywhere can be offered.
     */
    public function accepts(?Destination $destination, Parcel $parcel): bool
    {
        $regions = array_map(Region::of(...), $this->regions);
        if ($regions !== []) {
            $reached = false;
            foreach ($regions as $region) {
                $reached = $reached || ($destination !== null && $region->contains($destination));
            }
            if (!$reached) {
                return false;
            }
        }

        if ($this->postcodes !== []) {
            $postcode = $destination?->postcode;
            if ($postcode === null || $postcode === '' || array_filter($this->postcodes, static fn(string $pattern): bool => PostcodePattern::of($pattern)->matches($postcode)) === []) {
                return false;
            }
        }
        if ($this->classes !== [] && array_diff($parcel->classes, $this->classes) !== []) {
            return false;
        }

        return $this->weight->contains($parcel->weight)
            && $this->volume->contains($parcel->volume)
            && $this->length->contains($parcel->length)
            && $this->width->contains($parcel->width)
            && $this->height->contains($parcel->height)
            && $this->subtotal->contains(Measure::of((string) $parcel->subtotal->atLeastZero()));
    }

    /** The price before tax for this parcel. */
    public function priceFor(Parcel $parcel): Money
    {
        $price = Money::of($this->price);

        return $this->perItem ? $price->times($parcel->items) : $price;
    }
}
