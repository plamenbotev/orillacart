<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * What the setup knows about the shop that decides its taxes.
 */
final readonly class TaxSetup
{
    /**
     * @param list<string> $regions the US states or Canadian provinces where the shop must charge its tax (nexus, PST)
     */
    public function __construct(
        public string $shopCountry,
        public bool $consumers,
        public bool $businesses,
        /** Registered for VAT or GST, or with nexus somewhere in the US; false for a small business that charges none. */
        public bool $registered,
        /** In the EU's One-Stop Shop, or above its €10,000: EU consumers pay their own country's VAT. */
        public bool $oss = false,
        public array $regions = [],
        /** A tax service's id, for the US. */
        public ?string $provider = null,
        /** The rate of a country the shop knows nothing of, as the owner gave it. */
        public ?string $ownRate = null,
        /** The small-business note in the owner's words, instead of the country's usual one. */
        public ?string $smallBusinessNote = null,
    ) {}
}
