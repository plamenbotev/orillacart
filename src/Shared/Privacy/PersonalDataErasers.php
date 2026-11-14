<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Privacy;

/**
 * Every module's eraser, called in turn.
 */
final readonly class PersonalDataErasers
{
    /** @param iterable<ErasesPersonalData> $erasers */
    public function __construct(private iterable $erasers = []) {}

    public function erasedPerson(string $email): void
    {
        foreach ($this->erasers as $eraser) {
            $eraser->erasedPerson($email);
        }
    }

    public function erasedCustomer(int $customerId): void
    {
        foreach ($this->erasers as $eraser) {
            $eraser->erasedCustomer($customerId);
        }
    }

    public function erasedFromOrder(int $orderId, string $email): void
    {
        foreach ($this->erasers as $eraser) {
            $eraser->erasedFromOrder($orderId, $email);
        }
    }
}
