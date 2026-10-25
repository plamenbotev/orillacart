<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The account customers pay bank transfers into, which invoices give too.
 */
final readonly class BankAccountAnswers
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $holder,
        #[Assert\NotBlank]
        #[Assert\Length(max: 42)]
        public string $iban,
        #[Assert\Regex('/^([A-Za-z0-9]{8}|[A-Za-z0-9]{11})?$/')]
        public string $bic = '',
        #[Assert\Length(max: 100)]
        public string $bankName = '',
    ) {}
}
