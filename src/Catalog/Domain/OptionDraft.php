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
     * @param Money $adjustment added to the product price when the option is chosen; negative to take off
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public Money $adjustment,
    ) {
        if (trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CatalogRuleViolation(\sprintf('An option needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_option_name');
        }
    }
}
