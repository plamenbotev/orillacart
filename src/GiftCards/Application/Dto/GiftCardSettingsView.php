<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\GiftCards\Domain\GiftCardSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How the shop sells gift cards; also the command that changes it.
 */
final readonly class GiftCardSettingsView
{
    /**
     * @param list<int> $productIds products sold as gift cards
     */
    public function __construct(
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Positive()])]
        public array $productIds = [],
        #[Assert\Range(min: 1, max: GiftCardSettings::MAX_MONTHS)]
        public ?int $validMonths = null,
    ) {}

    public static function of(GiftCardSettings $settings): self
    {
        return new self($settings->productIds, $settings->validMonths);
    }
}
