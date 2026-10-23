<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A set of tax rates that products and shipping rates refer to, such as "Standard" or "Reduced".
 * The group owns its rates: they are added, changed and removed only through it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tax_groups')]
class TaxGroup
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

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

    /**
     * @return list<TaxRate> by priority, then in the order they were added
     */
    public function rates(): array
    {
        $rates = array_values($this->rates->toArray());
        usort($rates, static fn(TaxRate $a, TaxRate $b): int => $a->position() <=> $b->position());

        return $rates;
    }

    public function addRate(TaxRateDetails $details): TaxRate
    {
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
    public function taxesAt(TaxLocation $location): AppliedTaxes
    {
        $taxes = [];
        foreach ($this->rates() as $rate) {
            if ($rate->appliesAt($location)) {
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
