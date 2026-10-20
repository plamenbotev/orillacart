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
        /** A fee for paying this way, as the shop's prices are (before tax, or including it). */
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Use an amount of zero or more with up to four decimals.')]
        public string $fee = '0',
        /** And a percentage of the items, less discounts, and shipping. */
        #[Assert\Regex(pattern: '/^\d{1,3}(\.\d{1,4})?$/', message: 'Use a percentage from 0 to 100 with up to four decimals.')]
        #[Assert\Range(max: 100)]
        public string $feePercent = '0',
        /** What the totals call the fee; empty for the method's name. */
        #[Assert\Length(max: PaymentMethod::MAX_NAME_LENGTH)]
        public string $feeLabel = '',
    ) {}
}
