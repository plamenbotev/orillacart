<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The ways to pay that need no account elsewhere. PayPal and add-ons' gateways are set up on their own forms,
 * which keep their keys there.
 */
final readonly class PaymentsAnswers
{
    public function __construct(
        public bool $bankTransfer = false,
        public bool $cashOnDelivery = false,
        public bool $cheque = false,
        #[Assert\Valid]
        public ?BankAccountAnswers $bank = null,
    ) {}
}
