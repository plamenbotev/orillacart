<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\CartResult;
use OrillaCart\Checkout\Application\Dto\ReorderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\ReceiptLinkExpired;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Ordering again: the items of an order go in the cart with the same options, at today's prices. What is no
 * longer for sale, or not in stock enough, stays out, and the customer hears which.
 */
final readonly class Reorder
{
    public function __construct(
        private OrderService $orders,
        private CartService $carts,
    ) {}

    /**
     * @throws OrderNotFound      for a link the shop never made, or an order deleted since
     * @throws ReceiptLinkExpired
     * @throws RuleViolation      when the shop takes no orders online
     */
    public function fromReceipt(CartOwner $owner, string $receipt): ReorderView
    {
        $order = $this->orders->byReceipt($receipt);
        $token = $owner->token;
        $newToken = null;
        $result = null;
        $missed = [];
        foreach ($order->lines as $line) {
            try {
                $result = $this->carts->add(new CartOwner($token, $owner->customerId), new AddToCart($line->productId, $line->optionIds, $line->quantity, $line->bundleChoices));
            } catch (RuleViolation $e) {
                if ($e->errorCode === 'orillacart_catalog_only') {
                    throw $e;
                }
                $missed[] = $line->name;
                continue;
            }
            if ($result->newToken !== null) {
                $token = $newToken = $result->newToken;
            }
        }
        $result ??= $this->carts->cart(new CartOwner($token, $owner->customerId));

        return new ReorderView(new CartResult($result->cart, $newToken ?? $result->newToken), $missed);
    }
}
