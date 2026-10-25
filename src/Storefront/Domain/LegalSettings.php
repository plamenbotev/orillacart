<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * The pages the law asks a shop to show, and what checkout asks before an order: the terms of sale, the right
 * of withdrawal, shipping and payment, and the seller's legal notice; a box to accept the terms; the words of
 * the order button. A page the shop has none for is not linked.
 */
final readonly class LegalSettings
{
    public function __construct(
        public ?int $terms = null,
        public ?int $withdrawal = null,
        /** Shipping and payment: where the shop ships, what it costs, how customers pay. */
        public ?int $delivery = null,
        public ?int $legalNotice = null,
        /** Checkout asks the customer to accept the terms of sale before the order. */
        public bool $acceptTerms = false,
        public OrderButton $orderButton = OrderButton::PlaceOrder,
        /** The page of the withdrawal function, which every page links at its foot. */
        public ?int $withdrawalForm = null,
    ) {
        foreach ([$terms, $withdrawal, $delivery, $legalNotice, $withdrawalForm] as $page) {
            if ($page !== null && $page <= 0) {
                throw new \InvalidArgumentException('A page id is positive.');
            }
        }
        if ($acceptTerms && $terms === null) {
            throw new StorefrontRuleViolation('Choose the page of the terms of sale for checkout to ask for them to be accepted.', 'orillacart_terms_page_missing');
        }
    }
}
