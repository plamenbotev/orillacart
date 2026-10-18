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
use OrillaCart\Cart\Domain\Event\CartItemAdded;
use OrillaCart\Cart\Domain\Event\CartItemChanged;
use OrillaCart\Cart\Domain\Event\CartItemRemoved;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

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
        private SettingsService $settings,
        private InstalledExtensions $extensions,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
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
     * @throws CartRuleViolation  for a product not for sale, more than the stock allows, an add-on's cart rule, or a
     *                            shop that only shows its catalog
     */
    public function add(CartOwner $owner, AddToCart $command): CartResult
    {
        if ($this->settings->current()->catalogOnly) {
            throw new CartRuleViolation('The shop does not take orders online.', 'orillacart_catalog_only');
        }
        $quote = $this->quotes->quote(new QuoteRequest($command->productId, $command->optionIds, bundleChoices: $command->bundleChoices));
        if (!$quote->forSale) {
            throw new CartRuleViolation(\sprintf('%s is not for sale right now.', $quote->name), 'orillacart_not_for_sale');
        }

        [$cart, $newToken] = $this->find($owner);
        // Checked before the cart changes, so a refused change is never saved by whatever saves later.
        $existing = $cart?->lineFor($command->productId, $command->optionIds, $command->bundleChoices);
        $quantity = ($existing?->quantity() ?? 0) + $command->quantity;
        self::checkStock($quote, $quantity);
        $this->checkRules($cart, $owner->customerId, $existing, $quantity, $command->productId, $command->optionIds, $command->bundleChoices);
        if ($cart === null) {
            $newToken = self::token();
            $cart = new Cart($newToken, $owner->customerId, $this->clock->now());
        }
        $cart->add($command->productId, $command->optionIds, $command->quantity, $this->clock->now(), $command->bundleChoices);
        $this->carts->save($cart);
        $this->events->dispatch(new CartItemAdded($quote->productId, $quote->variationId, $command->quantity, $cart->customerId()));

        return new CartResult($this->view($cart), $newToken);
    }

    /**
     * @throws CartLineNotFound
     * @throws CartRuleViolation for more than the stock allows, or an add-on's cart rule
     */
    public function changeQuantity(CartOwner $owner, int $lineId, ChangeQuantity $command): CartResult
    {
        [$cart, $newToken] = $this->find($owner);
        if ($cart === null) {
            throw new CartLineNotFound($lineId);
        }
        $line = $cart->line($lineId);
        $previous = $line->quantity();
        $variationId = $this->variationOf($line);
        if ($command->quantity === 0) {
            $cart->remove($lineId, $this->clock->now());
            $event = new CartItemRemoved($line->productId(), $variationId, $previous, $cart->customerId());
        } else {
            if ($command->quantity > $previous) {
                self::checkStock($this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), bundleChoices: $line->bundleChoices())), $command->quantity);
                $this->checkRules($cart, $cart->customerId(), $line, $command->quantity, $line->productId(), $line->optionIds(), $line->bundleChoices());
            }
            $cart->changeQuantity($lineId, $command->quantity, $this->clock->now());
            $event = new CartItemChanged($line->productId(), $variationId, $command->quantity, $previous, $cart->customerId());
        }
        $this->carts->save($cart);
        if ($command->quantity !== $previous) {
            $this->events->dispatch($event);
        }

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
        $refusals = [];
        if ($this->extensions->cartRules() !== []) {
            $view = $this->view($cart);
            foreach ($view->lines as $line) {
                if ($line->problem === CartLineView::REFUSED && $line->message !== null) {
                    $refusals[] = $line->message;
                }
            }
            array_push($refusals, ...$view->refusals);
        }

        return new CartContents($cart->id(), array_map(static fn(CartLine $line): array => [
            'line' => $line->id(),
            'product_id' => $line->productId(),
            'option_ids' => $line->optionIds(),
            'bundle_choices' => $line->bundleChoices(),
            'quantity' => $line->quantity(),
        ], $cart->lines()), $cart->coupons(), $refusals);
    }

    /**
     * Keeps the coupon codes checkout accepted for the cart.
     *
     * @param list<string> $codes
     */
    public function useCoupons(int $cartId, array $codes): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart !== null) {
            $cart->useCoupons($codes, $this->clock->now());
            $this->carts->save($cart);
        }
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
     * Locks the cart for placing an order from it, in the placement's transaction, and tells which order is
     * already being placed from it: a second submit of the same checkout waits here for the first, then
     * learns of its order instead of placing another.
     *
     * @throws CartRuleViolation for a cart that is gone since it was read
     */
    public function lockForOrder(int $cartId): ?int
    {
        $cart = $this->carts->find($cartId, true) ?? throw new CartRuleViolation('The cart is empty.', 'orillacart_cart_empty');

        return $cart->orderBeingPlaced($this->clock->now());
    }

    /** The order was saved from the cart, in the transaction that locked it; its payment is about to start. */
    public function placing(int $cartId, int $orderId): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart !== null) {
            $cart->placing($orderId, $this->clock->now());
            $this->carts->save($cart);
        }
    }

    /** The order's payment could not start: the customer has the cart as it was. */
    public function notPlacing(int $cartId): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart !== null) {
            $cart->notPlacing();
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
        $quoted = [];
        $checked = [];
        foreach ($cart->lines() as $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), $line->quantity(), $cart->customerId(), $line->bundleChoices()));
            } catch (NotFound|RuleViolation) {
                $quote = null;
            }
            $quoted[] = [$line, $quote];
            if ($quote !== null) {
                $checked[] = self::checkedLine($line, $quote);
            }
        }
        [$refusedLines, $refusals] = $this->refusals(new CheckedCart($cart->customerId(), $checked, $this->settings->current()->currency));

        $lines = [];
        $items = 0;
        $subtotal = Money::zero();
        $orderable = !$cart->isEmpty() && $refusals === [];
        foreach ($quoted as [$line, $quote]) {
            if ($quote === null) {
                $lines[] = new CartLineView($line->id(), $line->productId(), null, '', null, [], $line->quantity(), '0', '0', 0, CartLineView::UNAVAILABLE);
                $orderable = false;
                continue;
            }
            $message = $refusedLines[$line->id()] ?? null;
            $problem = match (true) {
                !$quote->forSale => CartLineView::UNAVAILABLE,
                $quote->available !== null && $quote->available < $line->quantity() => CartLineView::INSUFFICIENT_STOCK,
                $message !== null => CartLineView::REFUSED,
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
                $quote->shownBackorder($line->quantity()),
                array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $quote->contents),
                $problem === CartLineView::REFUSED ? $message : null,
            );
            $items += $line->quantity();
            $subtotal = $subtotal->add($total);
            $orderable = $orderable && $problem === null;
        }

        return new CartView($lines, $items, (string) $subtotal, $orderable, $refusals);
    }

    /**
     * Refuses a change an add-on's cart rule refuses: the cart as it would be, with the target line (or a new one)
     * at the quantity.
     *
     * @param list<int> $optionIds
     * @param list<int> $bundleChoices
     *
     * @throws CartRuleViolation
     */
    private function checkRules(?Cart $cart, ?int $customerId, ?CartLine $target, int $quantity, int $productId, array $optionIds, array $bundleChoices): void
    {
        if ($this->extensions->cartRules() === []) {
            return;
        }
        $customerId = $cart?->customerId() ?? $customerId;
        $checked = [];
        foreach ($cart?->lines() ?? [] as $line) {
            if ($line === $target) {
                continue;
            }
            try {
                $checked[] = self::checkedLine($line, $this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), $line->quantity(), $customerId, $line->bundleChoices())));
            } catch (NotFound|RuleViolation) {
                // A line no longer for sale is the cart's to show, not this change's.
            }
        }
        $key = $target === null ? 0 : self::lineKey($target);
        $quote = $this->quotes->quote(new QuoteRequest($productId, $optionIds, $quantity, $customerId, $bundleChoices));
        $checked[] = new CheckedLine($key, $quote->productId, $quote->variationId, $quote->sku, $quote->name, $quantity, $quote->unitPrice);
        [$refusedLines] = $this->refusals(new CheckedCart($customerId, $checked, $this->settings->current()->currency));
        $message = $refusedLines[$key] ?? null;
        if ($message !== null) {
            throw new CartRuleViolation($message, 'orillacart_cart_refused');
        }
    }

    /**
     * What the add-ons' cart rules refuse: messages by line, and those about the whole cart. A rule that fails is
     * logged and left out, so one broken add-on does not stop every cart.
     *
     * @return array{array<int, string>, list<string>}
     */
    private function refusals(CheckedCart $cart): array
    {
        $lines = [];
        $whole = [];
        foreach ($this->extensions->cartRules() as $rule) {
            try {
                $refusals = $rule->check($cart);
            } catch (\Throwable $e) {
                $this->logger->error('The cart rule {rule} failed: {message}', ['rule' => $rule->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                continue;
            }
            foreach ($refusals as $refusal) {
                if ($refusal->lineId === null) {
                    $whole[] = $refusal->message;
                } else {
                    $lines[$refusal->lineId] ??= $refusal->message;
                }
            }
        }

        return [$lines, array_values(array_unique($whole))];
    }

    private static function checkedLine(CartLine $line, ItemQuote $quote): CheckedLine
    {
        return new CheckedLine(self::lineKey($line), $quote->productId, $quote->variationId, $quote->sku, $quote->name, $line->quantity(), $quote->unitPrice);
    }

    /** A line not saved yet, as one being added, is 0 to the rules. */
    private static function lineKey(CartLine $line): int
    {
        return $line->isSaved() ? $line->id() : 0;
    }

    private function variationOf(CartLine $line): ?int
    {
        try {
            return $this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), bundleChoices: $line->bundleChoices()))->variationId;
        } catch (NotFound|RuleViolation) {
            return null;
        }
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
