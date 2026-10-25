<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The account customers pay bank transfers into, which invoices give too: an IBAN and BIC, or an account number
 * and the bank's code (a routing number, a sort code) where banks number accounts otherwise.
 */
final readonly class BankAccountAnswers
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $holder,
        #[Assert\NotBlank]
        #[Assert\Length(max: 42)]
        public string $account,
        /** The BIC, or a routing number or sort code. */
        #[Assert\Regex('/^[A-Za-z0-9 -]{0,20}$/')]
        public string $bankCode = '',
        #[Assert\Length(max: 100)]
        public string $bankName = '',
    ) {}
}
