<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A time of day a carrier delivers in, as the Shipping screen saves it.
 */
final readonly class SaveDeliverySlot
{
    public function __construct(
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]{0,39}$/', message: 'Use lowercase letters, digits and dashes, up to 40.')]
        public string $id,
        #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use a time of day such as 09:00.')]
        public string $from,
        #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Use a time of day such as 12:00.')]
        public string $to,
        /** Orders a day; null for no limit. */
        #[Assert\Positive]
        public ?int $limit = null,
    ) {}
}
