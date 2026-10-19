<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * Which orders a list shows and in what order: every criterion given narrows it.
 */
final readonly class OrderSearch
{
    public const string BY_NUMBER = 'number';
    public const string BY_TOTAL = 'total';
    public const string ACCOUNTS = 'account';
    public const string GUESTS = 'guest';

    /**
     * @param string|null $text     an order number, or part of an email, a name or a phone
     * @param string|null $who      ACCOUNTS for customers' orders, GUESTS for guests'
     * @param string      $sortBy   BY_NUMBER (the order it was placed in) or BY_TOTAL
     */
    public function __construct(
        public ?OrderStatus $status = null,
        public ?string $text = null,
        public ?int $customerId = null,
        public bool $attentionOnly = false,
        public ?\DateTimeImmutable $placedFrom = null,
        public ?\DateTimeImmutable $placedBefore = null,
        public ?string $gateway = null,
        public ?Money $minTotal = null,
        public ?Money $maxTotal = null,
        public ?string $who = null,
        public string $sortBy = self::BY_NUMBER,
        public bool $ascending = false,
    ) {}

    public static function ofCustomer(int $customerId): self
    {
        return new self(customerId: $customerId);
    }
}
