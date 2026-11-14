<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\Privacy;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Shared\Privacy\ErasesPersonalData;

/**
 * The files a customer uploaded with an order (a photo to print) go when they are erased from it.
 */
final readonly class ForgetErasedUploads implements ErasesPersonalData
{
    public function __construct(private FormUploads $uploads) {}

    public function erasedFromOrder(int $orderId, string $email): void
    {
        $this->uploads->forgetOrder($orderId);
    }

    public function erasedPerson(string $email): void {}

    public function erasedCustomer(int $customerId): void {}
}
