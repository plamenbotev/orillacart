<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How the shop is registered for tax.
 */
final readonly class TaxAnswers
{
    /**
     * @param list<string> $regions US states with nexus, or Canadian provinces where the shop collects PST or QST
     */
    public function __construct(
        /** Registered for VAT or GST, or with nexus in a US state; false for a small business that charges none. */
        public bool $registered = true,
        /** In the EU's One-Stop Shop, or above its €10,000 of sales to other EU countries. */
        public bool $oss = false,
        #[Assert\Count(max: 70)]
        #[Assert\All([new Assert\Regex('/^[A-Z0-9]{1,3}$/')])]
        public array $regions = [],
        #[Assert\Regex('/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $provider = null,
        /** The rate to charge where OrillaCart knows none. */
        #[Assert\Regex('/^\d{1,2}(\.\d{1,4})?$/')]
        public ?string $ownRate = null,
        #[Assert\Length(max: 300)]
        public ?string $smallBusinessNote = null,
    ) {}
}
