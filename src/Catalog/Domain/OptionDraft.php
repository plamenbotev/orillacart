<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * An option as the admin screen sends it: an existing one keeps its id, a new one has none.
 */
final readonly class OptionDraft
{
    public const int MAX_NAME_LENGTH = 100;

    /**
     * @param Money $adjustment added to the product price when the option is chosen; negative to take off. For a
     *                          percentage, the percent as a decimal: 15 for 15 %
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public Money $adjustment,
        public AdjustmentKind $kind = AdjustmentKind::Amount,
    ) {
        if (trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CatalogRuleViolation('An option needs a name of up to %d characters.', 'orillacart_invalid_option_name', values: [self::MAX_NAME_LENGTH]);
        }
        if ($kind === AdjustmentKind::Percent
            && ($adjustment->compare(Money::of('-100')) < 0 || $adjustment->compare(Money::of(AdjustmentKind::MAX_PERCENT)) > 0)) {
            throw new CatalogRuleViolation('"%1$s" changes the price by %2$s %%; use -100 to %3$s.', 'orillacart_invalid_option_percent', values: [$name, $adjustment, AdjustmentKind::MAX_PERCENT]);
        }
    }
}
