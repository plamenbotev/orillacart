<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\TaxCode;

/**
 * A set of tax rates that products and shipping rates refer to, such as "Standard" or "Reduced".
 * The group owns its rates: they are added, changed and removed only through it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tax_groups')]
class TaxGroup
{
    public const int MAX_NAME_LENGTH = 100;

    /** Every rate is read to price an order; more than this belongs with a tax service. */
    public const int MAX_RATES = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    /** What tax services call the goods of the group; products may say otherwise. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $taxCode = null;

    /** @var Collection<int, TaxRate> */
    #[ORM\OneToMany(targetEntity: TaxRate::class, mappedBy: 'group', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['priority' => 'ASC', 'id' => 'ASC'])]
    private Collection $rates;

    public function __construct(string $name)
    {
        $this->name = self::validName($name);
        $this->rates = new ArrayCollection();
    }

    /**
     * @throws \LogicException before the group was saved
     */
    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The tax group has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = self::validName($name);
    }

    public function taxCode(): ?string
    {
        return $this->taxCode;
    }

    /**
     * @throws RuleViolation for one that cannot be a tax code
     */
    public function changeTaxCode(?string $code): void
    {
        try {
            $this->taxCode = TaxCode::valid($code);
        } catch (\InvalidArgumentException $e) {
            throw new RuleViolation($e->getMessage(), 'orillacart_invalid_tax_code');
        }
    }

    /**
     * @return list<TaxRate> by priority, then in the order they were added
     */
    public function rates(): array
    {
        $rates = array_values($this->rates->toArray());
        usort($rates, static fn(TaxRate $a, TaxRate $b): int => $a->position() <=> $b->position());

        return $rates;
    }

    /**
     * @throws RuleViolation once the group has MAX_RATES
     */
    public function addRate(TaxRateDetails $details): TaxRate
    {
        if ($this->rates->count() >= self::MAX_RATES) {
            throw new RuleViolation(\sprintf('A tax group holds up to %d rates; a tax service suits rates by street address.', self::MAX_RATES), 'orillacart_too_many_tax_rates');
        }
        $rate = new TaxRate($this, $details);
        $this->rates->add($rate);

        return $rate;
    }

    /**
     * @throws TaxRateNotFound
     */
    public function changeRate(int $rateId, TaxRateDetails $details): TaxRate
    {
        $rate = $this->rate($rateId);
        $rate->change($details);

        return $rate;
    }

    /**
     * @throws TaxRateNotFound
     */
    public function removeRate(int $rateId): void
    {
        $this->rates->removeElement($this->rate($rateId));
    }

    /**
     * @throws TaxRateNotFound
     */
    public function rate(int $rateId): TaxRate
    {
        return $this->rates->findFirst(static fn(int $key, TaxRate $rate): bool => $rate->isSaved() && $rate->id() === $rateId)
            ?? throw new TaxRateNotFound($rateId);
    }

    /**
     * The taxes of this group at a location: every rate for the whole country, plus the rates for the
     * location's state and postcode. They add up, listed by priority; a compound one on top of those before it.
     */
    /** The rates that apply at the location: to the items, or to shipping. */
    public function taxesAt(TaxLocation $location, bool $toShipping = false): AppliedTaxes
    {
        $taxes = [];
        foreach ($this->rates() as $rate) {
            if ($rate->appliesAt($location, $toShipping)) {
                $taxes[] = new AppliedTax($rate->isSaved() ? $rate->id() : null, $rate->details()->name, $rate->details()->percent, $rate->details()->compound, $rate->jurisdiction());
            }
        }

        return new AppliedTaxes($taxes);
    }

    private static function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new RuleViolation(\sprintf('A tax group needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_tax_group_name');
        }

        return $name;
    }
}
