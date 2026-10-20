<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One parcel of a shipment the shop sent: its tracking number, and where the customer follows it.
 */
final readonly class SaveParcel
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $number,
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 500)]
        public ?string $trackingUrl = null,
    ) {}
}
