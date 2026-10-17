<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

use OrillaCart\Payment\Domain\PaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: set up a payment method. A secret setting left empty keeps the saved secret.
 */
final readonly class SavePaymentMethod
{
    /**
     * @param list<string>               $countries where checkout offers it; empty for everywhere
     * @param array<string, string|bool> $settings  the gateway's settings by key
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: PaymentMethod::MAX_NAME_LENGTH)]
        public string $name,
        public bool $active = false,
        #[Assert\PositiveOrZero]
        public int $position = 0,
        #[Assert\All([new Assert\Regex(pattern: '/^[A-Z]{2}$/', message: 'Use a country code such as "BG".')])]
        public array $countries = [],
        #[Assert\Length(max: PaymentMethod::MAX_TEXT_LENGTH)]
        public string $instructions = '',
        #[Assert\Length(max: PaymentMethod::MAX_TEXT_LENGTH)]
        public string $receiptNote = '',
        public array $settings = [],
    ) {}
}
