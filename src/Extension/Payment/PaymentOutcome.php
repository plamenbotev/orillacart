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
    /** Money went back to the customer, perhaps by the shop at the provider's own site. */
    public const string REFUNDED = 'refunded';
    /** The money of a completed payment is in question: reversed, or disputed by the customer. Since version 3. */
    public const string DISPUTED = 'disputed';

    /**
     * @param string      $reference     the provider's id of the payment, as PaymentStart::redirect() gave it
     * @param string|null $transactionId the provider's id of the money moved, kept with the payment
     * @param string|null $amount        what was paid, or refunded, when the provider says
     * @param string|null $refundId      the provider's id of a refund
     */
    private function __construct(
        public string $reference,
        public string $status,
        public ?string $transactionId = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $refundId = null,
        /** What the provider said, for an outcome the shop has to look at. */
        public ?string $reason = null,
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

    /**
     * The money of a completed payment is in question: the provider reversed it, or the customer opened a
     * dispute or chargeback. The order keeps its status; the shop is asked to look.
     *
     * @param string      $reference     the payment's reference; empty when the provider names only the transaction
     * @param string|null $transactionId the payment's transaction, to find it by when the reference is empty
     */
    public static function disputed(string $reference, string $reason, ?string $transactionId = null): self
    {
        return new self($reference, self::DISPUTED, $transactionId, reason: $reason);
    }

    /**
     * Part or all of a completed payment went back. The shop records a refund it did not make itself, once per
     * $refundId; one it made is recognised by that id.
     *
     * @param string      $reference     the payment's reference; empty when the provider names only the transaction
     * @param string|null $transactionId the payment's transaction, to find it by when the reference is empty
     */
    public static function refunded(string $reference, string $refundId, string $amount, ?string $currency = null, ?string $transactionId = null): self
    {
        return new self($reference, self::REFUNDED, $transactionId, $amount, $currency, $refundId);
    }
}
