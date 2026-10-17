<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\CartResult;
use OrillaCart\Cart\Application\Dto\CartView;
use OrillaCart\Cart\Application\Dto\ChangeQuantity;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Cart\Domain\CartLine;
use OrillaCart\Cart\Domain\CartLineNotFound;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use Psr\Clock\ClockInterface;

/**
 * The customer's cart: filling it, and showing it at the catalog's current prices. Stock is checked when
 * items are added, and again when the order is placed; the cart itself holds none.
 */
final readonly class CartService
{
    /** Carts nobody changed for this long are deleted. */
    public const string KEEP_FOR = 'P30D';

    public function __construct(
        private CartRepository $carts,
        private ProductQuotes $quotes,
        private ClockInterface $clock,
    ) {}

    public function cart(CartOwner $owner): CartResult
    {
        [$cart, $newToken] = $this->find($owner);
        if ($cart === null) {
            return new CartResult(CartView::empty(), null);
        }
        if ($newToken !== null) {
            $this->carts->save($cart);
        }

        return new CartResult($this->view($cart), $newToken);
    }

    /**
     * @throws ProductNotFound
     * @throws InvalidSelection   for options the product does not offer, or required ones missing
     * @throws CartRuleViolation  for a product not for sale, or more than the stock allows
     */
    public function add(CartOwner $owner, AddToCart $command): CartResult
    {
        $quote = $this->quotes->quote(new QuoteRequest($command->productId, $command->optionIds));
        if (!$quote->forSale) {
            throw new CartRuleViolation(\sprintf('%s is not for sale right now.', $quote->name), 'orillacart_not_for_sale');
        }

        [$cart, $newToken] = $this->find($owner);
        if ($cart === null) {
            $newToken = self::token();
            $cart = new Cart($newToken, $owner->customerId, $this->clock->now());
        }
        $line = $cart->add($command->productId, $command->optionIds, $command->quantity, $this->clock->now());
        self::checkStock($quote, $line->quantity());
        $this->carts->save($cart);

        return new CartResult($this->view($cart), $newToken);
    }

    /**
     * @throws CartLineNotFound
     * @throws CartRuleViolation for more than the stock allows
     */
    public function changeQuantity(CartOwner $owner, int $lineId, ChangeQuantity $command): CartResult
    {
        [$cart, $newToken] = $this->find($owner);
        if ($cart === null) {
            throw new CartLineNotFound($lineId);
        }
        if ($command->quantity === 0) {
            $cart->remove($lineId, $this->clock->now());
        } else {
            $line = $cart->line($lineId);
            if ($command->quantity > $line->quantity()) {
                self::checkStock($this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds())), $command->quantity);
            }
            $cart->changeQuantity($lineId, $command->quantity, $this->clock->now());
        }
        $this->carts->save($cart);

        return new CartResult($this->view($cart), $newToken);
    }

    /**
     * @throws CartLineNotFound
     */
    public function remove(CartOwner $owner, int $lineId): CartResult
    {
        return $this->changeQuantity($owner, $lineId, new ChangeQuantity(0));
    }

    /**
     * What the cart holds, for checkout; null for no cart or an empty one.
     */
    public function contents(CartOwner $owner): ?CartContents
    {
        [$cart] = $this->find($owner);
        if ($cart === null || $cart->isEmpty()) {
            return null;
        }

        return new CartContents($cart->id(), array_map(static fn(CartLine $line): array => [
            'line' => $line->id(),
            'product_id' => $line->productId(),
            'option_ids' => $line->optionIds(),
            'quantity' => $line->quantity(),
        ], $cart->lines()));
    }

    /**
     * Empties the cart an order was placed from.
     */
    public function clear(int $cartId): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart !== null) {
            $cart->clear($this->clock->now());
            $this->carts->save($cart);
        }
    }

    /**
     * @return int how many carts were deleted
     */
    public function forgetAbandoned(): int
    {
        return $this->carts->removeUnchangedSince($this->clock->now()->sub(new \DateInterval(self::KEEP_FOR)));
    }

    /**
     * The owner's cart, and a new token when the browser has to be given one.
     *
     * @return array{?Cart, ?string}
     */
    private function find(CartOwner $owner): array
    {
        $cart = $owner->token === null || $owner->token === '' ? null : $this->carts->findByToken($owner->token);
        if ($cart !== null) {
            if ($owner->customerId !== null && $cart->customerId() === null) {
                $cart->belongTo($owner->customerId);
                $this->carts->save($cart);
            }

            return [$cart, null];
        }
        $cart = $owner->customerId === null ? null : $this->carts->findByCustomer($owner->customerId);
        if ($cart === null) {
            return [null, null];
        }
        $token = self::token();
        $cart->rekey($token);

        return [$cart, $token];
    }

    private function view(Cart $cart): CartView
    {
        $lines = [];
        $items = 0;
        $subtotal = Money::zero();
        $orderable = !$cart->isEmpty();
        foreach ($cart->lines() as $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds()));
            } catch (NotFound|RuleViolation) {
                $lines[] = new CartLineView($line->id(), $line->productId(), null, '', null, [], $line->quantity(), '0', '0', 0, 'unavailable');
                $orderable = false;
                continue;
            }
            $problem = match (true) {
                !$quote->forSale => 'unavailable',
                $quote->available !== null && $quote->available < $line->quantity() => 'insufficient_stock',
                default => null,
            };
            $total = Money::of($quote->unitPrice)->times($line->quantity());
            $lines[] = new CartLineView(
                $line->id(),
                $quote->productId,
                $quote->variationId,
                $quote->name,
                $quote->sku,
                array_map(static fn(QuotedOption $option): array => ['attribute' => $option->attribute, 'option' => $option->name], $quote->options),
                $line->quantity(),
                $quote->unitPrice,
                (string) $total,
                $quote->available,
                $problem,
            );
            $items += $line->quantity();
            $subtotal = $subtotal->add($total);
            $orderable = $orderable && $problem === null;
        }

        return new CartView($lines, $items, (string) $subtotal, $orderable);
    }

    private static function checkStock(ItemQuote $quote, int $quantity): void
    {
        if ($quote->available !== null && $quote->available < $quantity) {
            throw new CartRuleViolation(
                $quote->available === 0 ? \sprintf('%s is out of stock.', $quote->name) : \sprintf('Only %d of %s are in stock.', $quote->available, $quote->name),
                'orillacart_insufficient_stock',
            );
        }
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(32));
    }
}
