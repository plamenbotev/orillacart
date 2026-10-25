<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Who the shop sells to, and where.
 */
final readonly class SellingAnswers
{
    public const string CONSUMERS = 'consumers';
    public const string BUSINESSES = 'businesses';
    public const string BOTH = 'both';

    /**
     * @param list<string> $countries the countries, when the shop sells to a list of them
     */
    public function __construct(
        #[Assert\Choice(choices: [self::CONSUMERS, self::BUSINESSES, self::BOTH])]
        public string $customers,
        #[Assert\Choice(callback: [\OrillaCart\Setup\Domain\Regions::class, 'values'])]
        public string $regions,
        #[Assert\Count(max: 250)]
        #[Assert\All([new Assert\Regex('/^[A-Z]{2}$/')])]
        public array $countries = [],
    ) {}

    public function consumers(): bool
    {
        return $this->customers !== self::BUSINESSES;
    }

    public function businesses(): bool
    {
        return $this->customers !== self::CONSUMERS;
    }
}
