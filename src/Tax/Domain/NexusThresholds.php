<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The economic nexus thresholds the shop goes by: one for every US state, and the states whose own differ.
 * States change them, so the shop keeps them, from its tax adviser or the states' own pages.
 */
final readonly class NexusThresholds
{
    /** Most states count $100,000 of sales in a year; some also count 200 orders, a few count more. */
    public const string USUAL_SALES = '100000';
    public const int MAX_STATES = 60;

    /**
     * @param array<string, NexusThreshold> $states by state code, "TX"
     */
    public function __construct(
        public NexusThreshold $everywhere,
        public array $states = [],
    ) {
        if (\count($states) > self::MAX_STATES) {
            throw new RuleViolation('There are not that many states.', 'orillacart_invalid_nexus_threshold');
        }
        foreach (array_keys($states) as $state) {
            if (preg_match('/^[A-Z]{2}$/', (string) $state) !== 1) {
                throw new RuleViolation(\sprintf('"%s" is not a state code, such as TX.', $state), 'orillacart_invalid_region');
            }
        }
    }

    public static function defaults(): self
    {
        return new self(new NexusThreshold(self::USUAL_SALES, null));
    }

    public function of(string $state): NexusThreshold
    {
        return $this->states[$state] ?? $this->everywhere;
    }
}
