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

    public const string UNPAID = 'unpaid';
    public const string PAID = 'paid';
    public const string PARTLY_REFUNDED = 'partly_refunded';
    public const string REFUNDED = 'refunded';
    public const array PAYMENT_STATES = [self::UNPAID, self::PAID, self::PARTLY_REFUNDED, self::REFUNDED];

    public const string GIFT_CARD = 'gift_card';
    public const string BACKORDER = 'backorder';
    public const string PERSONALISED = 'personalised';
    public const string CUSTOMER_NOTE = 'note';
    public const string DOWNLOADS = 'downloads';
    /** What an order itself tells it has; other areas add theirs as marks. */
    public const array FACTS = [self::GIFT_CARD, self::BACKORDER, self::PERSONALISED, self::CUSTOMER_NOTE, self::DOWNLOADS];

    /**
     * @param list<OrderStatus>  $statuses     any of them
     * @param string|null        $text         an order number, or part of an email, a name, a phone, an address, a VAT
     *                                         number, a transaction id, or a line's name or SKU
     * @param list<int>          $foundIds     orders other areas found by the text: they match it too
     * @param string|null        $who          ACCOUNTS for customers' orders, GUESTS for guests'
     * @param string|null        $paid         one of PAYMENT_STATES
     * @param bool|null          $shipped      whether it has left for the customer (shipped or completed); null for either
     * @param string|null        $country      the billing or shipping country
     * @param list<string>       $facts        each of FACTS given
     * @param list<list<int>>    $markedIds    the orders each chosen mark is on: the order is in every list
     * @param string             $sortBy       BY_NUMBER (the order it was placed in) or BY_TOTAL
     */
    public function __construct(
        public array $statuses = [],
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
        public array $foundIds = [],
        public ?string $paid = null,
        public ?bool $shipped = null,
        public ?string $shippingName = null,
        public ?string $country = null,
        public ?\DateTimeImmutable $deliveryFrom = null,
        public ?\DateTimeImmutable $deliveryUntil = null,
        public array $facts = [],
        public array $markedIds = [],
        public ?string $currency = null,
    ) {}

    public static function ofCustomer(int $customerId): self
    {
        return new self(customerId: $customerId);
    }
}
