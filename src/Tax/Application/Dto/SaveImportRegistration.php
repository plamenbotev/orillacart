<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\ImportScheme;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Part of SaveImportSchemes: one scheme the shop sells under.
 */
final readonly class SaveImportRegistration
{
    public function __construct(
        #[Assert\Choice(callback: [ImportScheme::class, 'values'])]
        public string $scheme,
        /** The shop's number there; empty when it is not registered and ships as exports. */
        #[Assert\Length(max: 20)]
        #[Assert\Regex('/^[A-Za-z0-9 .\-]*$/', message: 'Use the number as the tax office gave it.')]
        public ?string $number = null,
        /** The limit in the shop's currency: what the goods are worth before shipping and tax. */
        #[Assert\Regex('/^\d{1,9}(\.\d{1,2})?$/', message: 'Use an amount such as 150.')]
        public string $limit = '0',
    ) {}
}
