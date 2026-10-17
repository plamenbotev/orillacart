<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A state, province or other ISO 3166-2 subdivision that addresses in its country name.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'states')]
#[ORM\UniqueConstraint(name: 'country_state', columns: ['country_code', 'code'])]
class State
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Created through Country::addState().
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'states')]
        #[ORM\JoinColumn(name: 'country_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
        public readonly Country $country,
        /** The ISO 3166-2 code without the country prefix: CA for US-CA. */
        #[ORM\Column(length: 3)]
        public readonly string $code,
        #[ORM\Column(length: 100)]
        public readonly string $name,
    ) {
        if (preg_match('/^[A-Z0-9]{1,3}$/', $code) !== 1) {
            throw new RuleViolation(\sprintf('"%s" is not a state code; use one to three capital letters or digits.', $code), 'orillacart_invalid_state');
        }
    }
}
