<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Privacy;

/**
 * What a module keeps of people apart from their accounts and orders (a log, failed jobs, uploaded files), forgotten
 * when a privacy request erases them. Customers and Ordering call every one, so a module below them in the tiers
 * forgets its part without knowing them; modules above may listen to their events instead.
 */
interface ErasesPersonalData
{
    public const string TAG = 'orillacart.erases_personal_data';

    /** What the shop holds of an email was erased, an account or not. */
    public function erasedPerson(string $email): void;

    /** A customer was erased; their account itself may stay. */
    public function erasedCustomer(int $customerId): void;

    /** The customer was erased from an order, which had this email. */
    public function erasedFromOrder(int $orderId, string $email): void;
}
