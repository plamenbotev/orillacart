<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application\Dto;

use OrillaCart\Settings\Domain\CurrencySettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The currencies shoppers may choose besides the shop's own; also what the shop saves.
 */
final readonly class CurrencySettingsView
{
    /**
     * @param list<OfferedCurrencyView> $offered
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(max: CurrencySettings::MAX_CURRENCIES)]
        public array $offered = [],
    ) {}

    public static function of(CurrencySettings $settings): self
    {
        return new self(array_map(OfferedCurrencyView::of(...), $settings->offered));
    }
}
