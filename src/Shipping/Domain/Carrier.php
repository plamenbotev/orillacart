<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A company or service that ships orders, such as "Courier", with its rates in the order checkout lists them.
 * An inactive carrier keeps its rates but checkout offers none of them.
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
            throw new ShippingRuleViolation(\sprintf('A carrier needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_carrier_name');
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
     * @throws ShippingRuleViolation for a rate id that is not this carrier's
     */
    public function replaceRates(array $drafts): void
    {
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
            $rate = $existing[$draft->id] ?? throw new ShippingRuleViolation(\sprintf('Shipping rate %d is not one of this carrier\'s rates.', $draft->id), 'orillacart_unknown_rate');
            if (isset($kept[$draft->id])) {
                throw new ShippingRuleViolation(\sprintf('Shipping rate %d is listed twice.', $draft->id), 'orillacart_duplicate_rate');
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
}
