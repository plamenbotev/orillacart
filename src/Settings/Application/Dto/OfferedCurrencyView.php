<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

use OrillaCart\Settings\Domain\OfferedCurrency;
use OrillaCart\Settings\Domain\PriceRounding;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A currency the shop offers besides its own; also what the shop saves for one.
 */
final readonly class OfferedCurrencyView
{
    public function __construct(
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Use a three-letter currency code such as USD.')]
        public string $code,
        /** Units of it for one of the shop's currency, such as "1.08". */
        #[Assert\Regex('/^\d{1,8}(\.\d{1,8})?$/', message: 'Use a rate such as 1.08, with up to eight decimals.')]
        public string $rate,
        #[Assert\Choice(callback: [PriceRounding::class, 'values'])]
        public string $rounding = 'decimals',
    ) {}

    public static function of(OfferedCurrency $offered): self
    {
        return new self($offered->currency->code, $offered->rate, $offered->rounding->value);
    }
}
