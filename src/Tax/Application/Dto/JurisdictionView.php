<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxJurisdiction;

/**
 * Who a tax is owed to, as orders keep it and returns are filed.
 */
final readonly class JurisdictionView
{
    public function __construct(
        public string $country,
        public ?string $region,
        /** "country", "region" or "local". */
        public string $level,
        public string $code,
        public string $name,
    ) {}

    public static function fromJurisdiction(TaxJurisdiction $jurisdiction): self
    {
        return new self($jurisdiction->country, $jurisdiction->region, $jurisdiction->level, $jurisdiction->code, $jurisdiction->name);
    }
}
