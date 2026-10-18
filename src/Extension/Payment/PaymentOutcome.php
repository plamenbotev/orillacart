<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * How a payment stands at the provider. A completed payment whose amount or currency differs from what the
 * order asked for is held for the shop to look at, not completed.
 */
final readonly class PaymentOutcome
{
    public const string COMPLETED = 'completed';
    /** The provider holds the money for review. */
    public const string HELD = 'held';
    public const string FAILED = 'failed';
    public const string CANCELLED = 'cancelled';
    /** Nothing is known yet. */
    public const string PENDING = 'pending';

    /**
     * @param string      $reference     the provider's id of the payment, as PaymentStart::redirect() gave it
     * @param string|null $transactionId the provider's id of the money moved, kept with the payment
     * @param string|null $amount        what was paid, when the provider says
     */
    private function __construct(
        public string $reference,
        public string $status,
        public ?string $transactionId = null,
        public ?string $amount = null,
        public ?string $currency = null,
    ) {}

    public static function completed(string $reference, string $transactionId, ?string $amount = null, ?string $currency = null): self
    {
        return new self($reference, self::COMPLETED, $transactionId, $amount, $currency);
    }

    public static function held(string $reference, ?string $transactionId = null): self
    {
        return new self($reference, self::HELD, $transactionId);
    }

    public static function failed(string $reference, ?string $transactionId = null): self
    {
        return new self($reference, self::FAILED, $transactionId);
    }

    public static function cancelled(string $reference): self
    {
        return new self($reference, self::CANCELLED);
    }

    public static function pending(string $reference): self
    {
        return new self($reference, self::PENDING);
    }
}
