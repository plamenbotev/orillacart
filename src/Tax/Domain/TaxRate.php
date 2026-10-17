<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One rate of a tax group: a percentage charged in a country, or only in one of its states.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tax_rates')]
#[ORM\Index(name: 'group_location', columns: ['group_id', 'country', 'state'])]
class TaxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $country;

    /** Null for a rate that applies in the whole country. */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $state;

    #[ORM\Column(type: 'decimal', precision: 7, scale: Percentage::DECIMALS)]
    private string $percent;

    #[ORM\Column(length: TaxRateDetails::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private int $priority;

    /**
     * Created through TaxGroup::addRate().
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaxGroup::class, inversedBy: 'rates')]
        #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
        private TaxGroup $group,
        TaxRateDetails $details,
    ) {
        $this->change($details);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The tax rate has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function group(): TaxGroup
    {
        return $this->group;
    }

    public function details(): TaxRateDetails
    {
        return new TaxRateDetails($this->country, $this->state, Percentage::of($this->percent), $this->name, $this->priority);
    }

    public function change(TaxRateDetails $details): void
    {
        $this->country = $details->country;
        $this->state = $details->state;
        $this->percent = $details->percent->toFixed();
        $this->name = $details->name;
        $this->priority = $details->priority;
    }

    public function appliesAt(TaxLocation $location): bool
    {
        return $this->country === $location->country && ($this->state === null || $this->state === $location->state);
    }

    /**
     * Rates of the same priority are listed by id; rates not saved yet come last, in the order they were added.
     *
     * @internal for TaxGroup's ordering
     *
     * @return array{int, int}
     */
    public function position(): array
    {
        return [$this->priority, $this->id ?? PHP_INT_MAX];
    }
}
