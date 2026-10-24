<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * An ISO 3166-1 country with the states or provinces addresses in it name. Reference data: seeded by a
 * migration and never changed by the shop.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'countries')]
#[ORM\UniqueConstraint(name: 'alpha3', columns: ['alpha3'])]
class Country
{
    /** @var Collection<int, State> */
    #[ORM\OneToMany(targetEntity: State::class, mappedBy: 'country')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $states;

    public function __construct(
        /** ISO 3166-1 alpha-2, the code every other area stores. */
        #[ORM\Id]
        #[ORM\Column(length: 2, options: ['fixed' => true])]
        public readonly string $code,
        #[ORM\Column(length: 3, options: ['fixed' => true])]
        public readonly string $alpha3,
        #[ORM\Column(length: 100)]
        public readonly string $name,
        #[ORM\Column]
        public readonly bool $inEu,
    ) {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new RuleViolation('"%s" is not a country code; use two capital letters such as DE.', 'orillacart_invalid_country', values: [$code]);
        }
        $this->states = new ArrayCollection();
    }

    public function addState(string $code, string $name): State
    {
        $state = new State($this, $code, $name);
        $this->states->add($state);

        return $state;
    }

    /**
     * @return list<State> by name
     */
    public function states(): array
    {
        return array_values($this->states->toArray());
    }

    public function hasStates(): bool
    {
        return !$this->states->isEmpty();
    }

    public function state(string $code): ?State
    {
        return $this->states->findFirst(static fn(int $key, State $state): bool => $state->code === $code);
    }
}
