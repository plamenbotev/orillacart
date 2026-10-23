<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderSearch;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a page of orders, newest first unless sorted otherwise. The search matches the order number, the email,
 * names, phones and addresses, the VAT number, the transaction id, a line's name or SKU, and what other areas
 * find by it (tracking, invoice and coupon numbers). The other filters narrow by status (one or several), when it
 * was placed (moments, as the shop's days begin and end), the way it was paid, its total, who placed it, payment
 * and refunds, shipping, country, delivery day, currency, and what it has.
 */
final readonly class OrderQuery
{
    public const int MAX_PER_PAGE = 100;
    public const string STATUSES = '/^(pending_payment|on_hold|processing|shipped|completed|cancelled|failed|refunded)(,(pending_payment|on_hold|processing|shipped|completed|cancelled|failed|refunded)){0,7}$/';

    public function __construct(
        /** One status or several, comma-separated: "processing,on_hold". */
        #[Assert\Regex(pattern: self::STATUSES, message: 'Choose order statuses.')]
        public ?string $status = null,
        #[Assert\Length(max: 100)]
        public ?string $search = null,
        #[Assert\Positive]
        public ?int $customerId = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: self::MAX_PER_PAGE)]
        public int $perPage = 20,
        /** Only orders the shop has to look at. */
        public bool $attention = false,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $placedFrom = null,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $placedBefore = null,
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}$/')]
        public ?string $payment = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount such as 25.50.')]
        public ?string $minTotal = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,4})?$/', message: 'Enter an amount such as 25.50.')]
        public ?string $maxTotal = null,
        #[Assert\Choice(choices: [OrderSearch::ACCOUNTS, OrderSearch::GUESTS])]
        public ?string $placedBy = null,
        #[Assert\Choice(choices: [OrderSearch::BY_NUMBER, OrderSearch::BY_TOTAL])]
        public string $orderBy = OrderSearch::BY_NUMBER,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $order = 'desc',
        #[Assert\Choice(choices: OrderSearch::PAYMENT_STATES)]
        public ?string $paid = null,
        #[Assert\Choice(choices: ['yes', 'no'])]
        public ?string $shipped = null,
        /** The way it ships, as the order names it. */
        #[Assert\Length(max: 200)]
        public ?string $shipping = null,
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/')]
        public ?string $country = null,
        #[Assert\Date]
        public ?string $deliveryFrom = null,
        #[Assert\Date]
        public ?string $deliveryUntil = null,
        /** What it has, comma-separated: the order's own facts (OrderSearch::FACTS) and other areas' marks. */
        #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{0,39}(,[a-z][a-z0-9_]{0,39}){0,19}$/')]
        public ?string $has = null,
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/')]
        public ?string $currency = null,
    ) {}

    /** The same query for another page: an export walks every page of it. */
    public function withPage(int $page, int $perPage): self
    {
        return new self(
            $this->status,
            $this->search,
            $this->customerId,
            $page,
            $perPage,
            $this->attention,
            $this->placedFrom,
            $this->placedBefore,
            $this->payment,
            $this->minTotal,
            $this->maxTotal,
            $this->placedBy,
            $this->orderBy,
            $this->order,
            $this->paid,
            $this->shipped,
            $this->shipping,
            $this->country,
            $this->deliveryFrom,
            $this->deliveryUntil,
            $this->has,
            $this->currency,
        );
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->status === null ? [] : array_values(array_unique(explode(',', $this->status)));
    }

    /** @return list<string> */
    public function has(): array
    {
        return $this->has === null ? [] : array_values(array_unique(explode(',', $this->has)));
    }
}
