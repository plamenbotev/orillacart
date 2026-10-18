<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * What the EU's VIES service said of a VAT number.
 */
final readonly class VatCheck
{
    public const string VALID = 'valid';
    public const string INVALID = 'invalid';
    /** VIES, or the member state's register behind it, could not answer. */
    public const string UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        /** The number as VIES knows it, its country prefix first: DE123456789. */
        public string $number,
        /** The registered name, when VIES gives it. */
        public ?string $name = null,
    ) {}
}
