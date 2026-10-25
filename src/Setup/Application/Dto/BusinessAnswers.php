<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Who sells and from where: asked once for the shop's settings and its invoices.
 */
final readonly class BusinessAnswers
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $legalName,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $street,
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $city,
        #[Assert\Length(max: 20)]
        public string $postcode,
        #[Assert\Regex('/^[A-Z]{2}$/')]
        public string $country,
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public string $email,
        #[Assert\Regex('/^[A-Z]{3}$/')]
        public string $currency,
        #[Assert\Regex('/^[A-Z0-9]{1,3}$/')]
        public ?string $state = null,
        #[Assert\Regex('/^(\+?[0-9 ().\/-]{3,30})?$/')]
        public string $phone = '',
        /** The VAT or other tax number, if the business has one. */
        #[Assert\Length(max: 30)]
        public string $vatNumber = '',
        #[Assert\Length(max: 50)]
        public string $companyNumber = '',
    ) {}
}
