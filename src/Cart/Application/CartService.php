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
use OrillaCart\Cart\Application\Dto\CheckoutContact;
use OrillaCart\Cart\Domain\AbandonedCartRepository;
use OrillaCart\Cart\Domain\AddOnLine;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Cart\Domain\CartLine;
use OrillaCart\Cart\Domain\CartLineNotFound;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Cart\Domain\ChargeLine;
use OrillaCart\Cart\Domain\Event\CartChanged;
use OrillaCart\Cart\Domain\Event\CartItemAdded;
use OrillaCart\Cart\Domain\Event\CartItemChanged;
use OrillaCart\Cart\Domain\Event\CartItemRemoved;
use OrillaCart\Cart\Domain\Event\LeftCartOrdered;
use OrillaCart\Cart\Domain\GivenItem;
use OrillaCart\Cart\Domain\GivenLine;
use OrillaCart\Cart\Domain\ProductLine;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\Cart\KeptCharge;
use OrillaCart\Extension\Cart\KeptItem;
use OrillaCart\Forms\Application\Dto\GivenAnswer;
use OrillaCart\Forms\Application\FieldAnswers;
use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\InvalidFields;
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
    /** Why a line whose answers no longer hold cannot be ordered. */
    public const string CHANGED = 'Its choices changed: remove it and add it again.';

    /** Carts nobody changed for this long are deleted. */
    public const string KEEP_FOR = 'P30D';

    public function __construct(
        private CartRepository $carts,
        private AbandonedCartRepository $abandoned,
        private ProductQuotes $quotes,
        private ClockInterface $clock,
        private SettingsService $settings,
        private InstalledExtensions $extensions,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
        private Currencies $currencies,
        private ShopForms $forms,
        private ProductContent $content,
        private FormUploads $uploads,
        private ProductTranslations $translations,
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
        // Added from a translation's page, the line is its original's: the product (design 51).
        $command = new AddToCart(
            productId: $this->translations->original($command->productId),
            optionIds: $command->optionIds,
            quantity: $command->quantity,
            bundleChoices: $command->bundleChoices,
            personalisation: $command->personalisation,
        );
        // Only the questions the product's forms ask are read; files by the token their sender was given.
        $answers = $this->forms->productAnswers($command->productId, $this->content->termIds($command->productId)['categories'], $command->personalisation);
        $values = FieldAnswers::values($answers);
        $quote = $this->quotes->quote(new QuoteRequest($command->productId, $command->optionIds, bundleChoices: $command->bundleChoices, extra: (string) FieldAnswers::price($answers)));
        if (!$quote->forSale) {
            throw new CartRuleViolation('%s is not for sale right now.', 'orillacart_not_for_sale', values: [$quote->name]);
        }

        [$cart, $newToken] = $this->find($owner);
        // Checked before the cart changes, so a refused change is never saved by whatever saves later.
        $existing = $cart?->lineFor($command->productId, $command->optionIds, $command->bundleChoices, $values);
        $files = FieldAnswers::uploadIds($answers);
        if ($files !== [] && $existing === null && self::files($cart) + \count($files) > FormUploads::MAX_HELD) {
            throw new CartRuleViolation('A cart holds up to %d files sent with its items.', 'orillacart_cart_files', values: [FormUploads::MAX_HELD]);
        }
        $quantity = ($existing?->quantity() ?? 0) + $command->quantity;
        self::checkStock($quote, $quantity);
        $this->checkRules($cart, $owner->customerId, $existing, $quantity, $command->productId, $command->optionIds, $command->bundleChoices);
        if ($cart === null) {
            $newToken = self::token();
            $cart = new Cart($newToken, $owner->customerId, $this->clock->now());
        }
        $cart->add($command->productId, $command->optionIds, $command->quantity, $this->clock->now(), $command->bundleChoices, $values);
        $this->keepAddOns($cart);
        $this->carts->save($cart);
        // Files in a cart last as long as it does.
        $this->uploads->hold($files);
        $this->events->dispatch(new CartItemAdded($quote->productId, $quote->variationId, $command->quantity, $cart->customerId()));
        $this->events->dispatch(new CartChanged($cart->id(), $cart->customerId()));

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
            $this->keepAddOns($cart);
            $event = new CartItemRemoved($line->productId(), $variationId, $previous, $cart->customerId());
        } else {
            if ($command->quantity > $previous) {
                self::checkStock($this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), bundleChoices: $line->bundleChoices())), $command->quantity);
                $this->checkRules($cart, $cart->customerId(), $line, $command->quantity, $line->productId(), $line->optionIds(), $line->bundleChoices());
            }
            $cart->changeQuantity($lineId, $command->quantity, $this->clock->now());
            $this->keepAddOns($cart);
            $event = new CartItemChanged($line->productId(), $variationId, $command->quantity, $previous, $cart->customerId());
        }
        $this->carts->save($cart);
        if ($command->quantity !== $previous) {
            $this->events->dispatch($event);
            $this->events->dispatch(new CartChanged($cart->id(), $cart->customerId()));
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

        return $cart === null ? null : $this->contentsOf($cart);
    }

    /**
     * What the cart holds, by its id; null for no cart or an empty one.
     */
    public function contentsById(int $cartId): ?CartContents
    {
        $cart = $this->carts->find($cartId);

        return $cart === null ? null : $this->contentsOf($cart);
    }

    private function contentsOf(Cart $cart): ?CartContents
    {
        if ($cart->isEmpty()) {
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

        $lines = [];
        $charges = [];
        foreach ($cart->lines() as $line) {
            if ($line instanceof ChargeLine) {
                $charges[] = ['label' => $line->label(), 'amount' => (string) $line->amount(), 'quantity' => $line->quantity(), 'taxable' => $line->taxable(), 'tax_group_id' => $line->taxGroupId(), 'source' => Cart::ADD_ON . $line->addOnType()];
                continue;
            }
            $answers = $this->answersOf($line);
            if ($answers === null) {
                $refusals[] = \sprintf('%s: %s', $this->content->name($line->productId()), self::CHANGED);
                $answers = [];
            }
            $lines[] = [
                'line' => $line->id(),
                'product_id' => $line->productId(),
                'option_ids' => $line->optionIds(),
                'bundle_choices' => $line->bundleChoices(),
                'quantity' => $line->quantity(),
                'extra' => (string) FieldAnswers::price($answers),
                'personalisation' => FieldAnswers::rows($answers),
                'given' => $line->givenBy(),
                'add_on' => $line instanceof AddOnLine ? ['type' => $line->addOnType(), 'label' => $line->label()] : null,
            ];
        }

        return new CartContents($cart->id(), $lines, $cart->coupons(), array_values(array_unique($refusals)), $cart->giftCards(), $cart->usesStoreCredit(), $cart->fingerprint(), $cart->customerId(), $cart->declined(), $charges);
    }

    /**
     * Keeps the items discounts give in the cart in step with what they give now (Cart::keepGiven()).
     *
     * @param list<GivenItem> $items
     *
     * @return bool whether the cart changed
     */
    public function keepGiven(int $cartId, array $items): bool
    {
        $cart = $this->carts->find($cartId);
        if ($cart === null || !$cart->keepGiven($items, $this->clock->now())) {
            return false;
        }
        $this->carts->save($cart);

        return true;
    }

    /**
     * The shopper wants the items a discount gives after all, having removed them: they are given again.
     */
    public function restoreGiven(CartOwner $owner, string $code): CartResult
    {
        [$cart, $newToken] = $this->find($owner);
        if ($cart === null) {
            return new CartResult(CartView::empty(), null);
        }
        $cart->undecline($code, $this->clock->now());
        $this->keepAddOns($cart);
        $this->carts->save($cart);
        $this->events->dispatch(new CartChanged($cart->id(), $cart->customerId()));

        return new CartResult($this->view($cart), $newToken);
    }

    /**
     * Keeps the gift card codes the customer entered, and whether they pay with their store credit.
     *
     * @param list<string> $codes
     */
    public function useGiftCards(int $cartId, array $codes, bool $storeCredit): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart !== null) {
            $cart->useGiftCards($codes, $this->clock->now());
            $cart->useStoreCredit($storeCredit, $this->clock->now());
            $this->carts->save($cart);
        }
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
            $this->events->dispatch(new CartChanged($cart->id(), $cart->customerId()));
        }
    }

    /**
     * Empties the cart an order was placed from. A cart left at checkout before is linked to the order, and counts recovered once it is paid.
     *
     * @param string|null $orderTotal    what the order costs, for the report
     * @param string|null $orderCurrency the order's currency; the report counts in the shop's
     */
    public function clear(int $cartId, ?int $orderId = null, ?string $orderTotal = null, ?string $orderCurrency = null): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart === null) {
            return;
        }
        $abandonedId = $cart->abandonedId();
        $left = $abandonedId === null || $orderId === null ? null : $this->abandoned->find($abandonedId);
        if ($left !== null && $orderId !== null && !$left->isOrdered()) {
            // The abandoned cart was kept in the shop's currency: an order in another is counted back in it.
            $total = Money::of($orderTotal ?? '0');
            $left->ordered($orderId, ($orderCurrency === null ? $total : $this->currencies->back($total, $orderCurrency))->toFixed());
            $this->abandoned->save($left);
            $this->events->dispatch(new LeftCartOrdered($orderId));
        }
        $cart->clear($this->clock->now());
        $this->carts->save($cart);
    }

    /**
     * The customer is at checkout with the cart: their email, and whether they ask to be reminded of the cart.
     *
     * @return string|null a new token for the browser, when it has to be given one
     */
    public function atCheckout(CartOwner $owner, CheckoutContact $contact, ?string $locale): ?string
    {
        [$cart, $newToken] = $this->find($owner);
        if ($cart === null) {
            return null;
        }
        $email = $contact->email === null || trim($contact->email) === '' ? null : strtolower(trim($contact->email));
        $cart->atCheckout($email, $contact->remind, $locale, $this->clock->now());
        $this->carts->save($cart);

        return $newToken;
    }

    /**
     * The visitor's cart a reminder's link brings back, to this browser: a cart of a customer's account is theirs
     * to log in to instead.
     *
     * @return string|null the token for the browser; null for no such cart
     */
    public function recover(string $token): ?string
    {
        $cart = $this->carts->findByRecoverToken($token);
        if ($cart === null || $cart->customerId() !== null) {
            return null;
        }
        $newToken = self::token();
        $cart->rekey($newToken);
        $this->carts->save($cart);

        return $newToken;
    }

    /** Puts a coupon code a reminder offered in the owner's cart; checkout decides whether it holds. */
    public function offerCoupon(CartOwner $owner, string $code): ?string
    {
        [$cart, $newToken] = $this->find($owner);
        if ($cart === null || trim($code) === '') {
            return $newToken;
        }
        $cart->useCoupons([...$cart->coupons(), trim($code)], $this->clock->now());
        $this->carts->save($cart);

        return $newToken;
    }

    /** A cart as the customer would see it now; null for one that is gone. */
    public function viewOf(int $cartId): ?CartView
    {
        $cart = $this->carts->find($cartId);

        return $cart === null ? null : $this->view($cart);
    }

    /**
     * Locks the cart for placing an order from it, in the placement's transaction, and tells which order is
     * already being placed from it: a second submit of the same checkout waits here for the first, then
     * learns of its order instead of placing another.
     *
     * @param string|null $fingerprint the cart as checkout read it (CartContents::$fingerprint); the order is placed
     *                                 from that cart only, not from one emptied (by the order placed from it) or
     *                                 changed since
     *
     * @throws CartRuleViolation for a cart that is gone, emptied or changed since it was read
     */
    public function lockForOrder(int $cartId, ?string $fingerprint = null): ?int
    {
        $cart = $this->carts->find($cartId, true) ?? throw new CartRuleViolation('The cart is empty.', 'orillacart_cart_empty');
        $placing = $cart->orderBeingPlaced($this->clock->now());
        if ($placing !== null) {
            return $placing;
        }
        if ($cart->isEmpty()) {
            throw new CartRuleViolation('The cart is empty.', 'orillacart_cart_empty');
        }
        if ($fingerprint !== null && $cart->fingerprint() !== $fingerprint) {
            throw new CartRuleViolation('The cart changed while the order was being placed. Review it and try again.', 'orillacart_cart_changed');
        }

        return null;
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

    /** The order's payment could not start: the customer has the cart as it was, the files its lines name too. */
    public function notPlacing(int $cartId): void
    {
        $cart = $this->carts->find($cartId);
        if ($cart !== null) {
            $orderId = $cart->placingOrderId();
            if ($orderId !== null) {
                // The cancelled order took them; ordered again, they would be refused as another order's.
                $this->uploads->giveBack($orderId, $cart->fileHashes());
            }
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
     * A visitor with a cart logged in: the cart becomes theirs, joined with what their account's cart held, under
     * a new token, so whoever knew the visitor's cookie does not reach the account's cart.
     *
     * @return array{Cart, string}
     */
    private function loggedIn(Cart $visitors, int $customerId): array
    {
        $now = $this->clock->now();
        $cart = $this->carts->findByCustomer($customerId);
        if ($cart !== null && $cart->id() !== $visitors->id()) {
            // Within the files a cart holds: a line whose files would pass the limit stays out, as adding it would.
            $cart->takeFrom($visitors, $now, FormUploads::MAX_HELD);
            $this->carts->remove($visitors);
        } else {
            $cart = $visitors;
            $cart->belongTo($customerId);
        }
        $token = self::token();
        $cart->rekey($token);
        $this->keepAddOns($cart);
        $this->carts->save($cart);
        // The files it names last as long as the cart does from now.
        $this->uploads->holdNamed($cart->fileHashes());
        $this->events->dispatch(new CartChanged($cart->id(), $cart->customerId()));

        return [$cart, $token];
    }

    /**
     * The owner's cart, and a new token when the browser has to be given one.
     *
     * @return array{?Cart, ?string}
     */
    private function find(CartOwner $owner): array
    {
        $cart = $owner->token === null || $owner->token === '' ? null : $this->carts->findByToken($owner->token);
        if ($cart !== null && $cart->customerId() !== null && $cart->customerId() !== $owner->customerId) {
            // Another customer's cart, left in a shared browser: not this one's to see or order from.
            $cart = null;
        }
        if ($cart !== null) {
            if ($owner->customerId === null || $cart->customerId() !== null) {
                return [$cart, null];
            }

            return $this->loggedIn($cart, $owner->customerId);
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
        // Prices by quantity count every line of the product bought: its variations and option choices together,
        // not those a discount gives, as checkout counts them.
        $ofProduct = [];
        $charges = [];
        foreach ($cart->lines() as $line) {
            if ($line instanceof ChargeLine) {
                $charges[] = $line;
                continue;
            }
            $ofProduct[$line->productId()] = ($ofProduct[$line->productId()] ?? 0) + ($line instanceof GivenLine ? 0 : $line->quantity());
        }
        // The cart's products read at once, not a query a line.
        $this->quotes->warm(array_keys($ofProduct));
        foreach ($cart->lines() as $line) {
            if ($line instanceof ChargeLine) {
                continue;
            }
            $answers = $this->answersOf($line);
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), max(1, $ofProduct[$line->productId()]), $cart->customerId(), $line->bundleChoices(), (string) FieldAnswers::price($answers ?? [])));
            } catch (NotFound|RuleViolation) {
                $quote = null;
            }
            $quoted[] = [$line, $quote, $answers];
            if ($quote !== null) {
                $checked[] = self::checkedLine($line, $quote);
            }
        }
        [$refusedLines, $refusals] = $this->refusals(new CheckedCart($cart->customerId(), $checked, $this->settings->current()->currency));

        $lines = [];
        $items = 0;
        $subtotal = Money::zero();
        $orderable = !$cart->isEmpty() && $refusals === [];
        // In the shopper's currency, as checkout prices it.
        $currency = $this->currencies->current();
        foreach ($quoted as [$line, $quote, $answers]) {
            if ($quote === null) {
                $lines[] = new CartLineView($line->id(), $line->productId(), null, '', null, [], $line->quantity(), '0', '0', 0, CartLineView::UNAVAILABLE, type: $line->type(), quantityChangeable: $line->quantityChangeable(), givenBy: $line->givenBy());
                $orderable = false;
                continue;
            }
            $message = $refusedLines[$line->id()] ?? null;
            $problem = match (true) {
                !$quote->forSale => CartLineView::UNAVAILABLE,
                $quote->available !== null && $quote->available < $line->quantity() => CartLineView::INSUFFICIENT_STOCK,
                $answers === null => CartLineView::CHANGED,
                $message !== null => CartLineView::REFUSED,
                default => null,
            };
            $unitPrice = $this->currencies->price(Money::of($quote->unitPrice), $currency);
            // An item given costs nothing: the discount that gives it takes off its price.
            $total = $line->givenBy() === null ? $unitPrice->times($line->quantity()) : Money::zero();
            $lines[] = new CartLineView(
                $line->id(),
                $quote->productId,
                $quote->variationId,
                $quote->name,
                $quote->sku,
                array_map(static fn(QuotedOption $option): array => ['attribute' => $option->attribute, 'option' => $option->name], $quote->options),
                $line->quantity(),
                (string) $unitPrice,
                (string) $total,
                $quote->available,
                $problem,
                $quote->shownBackorder($line->quantity()),
                array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $quote->contents),
                match ($problem) {
                    CartLineView::REFUSED => $message,
                    CartLineView::CHANGED => self::CHANGED,
                    default => null,
                },
                array_map(fn(GivenAnswer $answer): array => ['label' => $answer->label, 'answer' => $answer->answer, 'price' => $answer->price === null ? null : (string) $this->currencies->price(Money::of($answer->price), $currency)], $answers ?? []),
                $line->type(),
                $line->quantityChangeable(),
                $line->givenBy(),
                $line instanceof GivenLine || $line instanceof AddOnLine ? $line->label() : null,
                $line->removable(),
            );
            $items += $line->quantity();
            $subtotal = $subtotal->add($total);
            $orderable = $orderable && $problem === null;
        }

        // A charge after the items, as checkout prices it: so much each, as the shop's prices are, in the currency.
        foreach ($charges as $line) {
            $unit = $this->currencies->amount($line->amount(), $currency);
            $total = $unit->times($line->quantity());
            $lines[] = new CartLineView($line->id(), 0, null, $line->label(), null, [], $line->quantity(), (string) $unit, (string) $total, null, null, type: $line->type(), quantityChangeable: false, removable: $line->removable());
            $subtotal = $subtotal->add($total);
        }

        return new CartView($lines, $items, (string) $subtotal, $orderable, $refusals, $currency);
    }

    /** How many files the cart's lines name. */
    private static function files(?Cart $cart): int
    {
        $files = 0;
        foreach ($cart?->lines() ?? [] as $line) {
            $files += \count(array_filter($line->answers(), static fn(string $value): bool => preg_match('/^#[a-f0-9]{64}$/', $value) === 1));
        }

        return $files;
    }

    /**
     * The line's answers checked against the product's forms as they are now; null when they no longer hold (a
     * required question added, a choice or a file gone).
     *
     * @return list<GivenAnswer>|null
     */
    private function answersOf(CartLine $line): ?array
    {
        try {
            return $this->forms->productAnswers($line->productId(), $this->content->termIds($line->productId())['categories'], $line->answers(), kept: true);
        } catch (InvalidFields) {
            return null;
        }
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
     * Keeps the lines add-ons' cart line types keep in step with the shopper's own items: catalog products for
     * sale, in their quantity. A type that throws is logged and keeps its lines as they are.
     */
    private function keepAddOns(Cart $cart): void
    {
        $types = $this->extensions->cartLineTypes();
        if ($types === []) {
            return;
        }
        $checked = [];
        foreach ($cart->lines() as $line) {
            if (!$line instanceof ProductLine) {
                continue;
            }
            try {
                $checked[] = self::checkedLine($line, $this->quotes->quote(new QuoteRequest($line->productId(), $line->optionIds(), $line->quantity(), $cart->customerId(), $line->bundleChoices())));
            } catch (NotFound|RuleViolation) {
            }
        }
        $own = new CheckedCart($cart->customerId(), $checked, $this->settings->current()->currency);
        foreach ($types as $type) {
            try {
                $items = $type->lines($own);
                $label = $type->label();
                $removable = $type->removable();
            } catch (\Throwable $e) {
                $this->logger->error('The cart line type {type} failed: {message}', ['type' => $type->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                continue;
            }
            $kept = [];
            $charges = [];
            foreach ($items as $item) {
                if ($item instanceof KeptCharge) {
                    $label = trim($item->label);
                    if ($label !== '' && mb_strlen($label) <= 100 && $item->quantity >= 1 && $item->quantity <= Cart::MAX_QUANTITY && preg_match('/^\d{1,11}(\.\d{1,' . Money::DECIMALS . '})?$/', $item->amount) === 1 && !Money::of($item->amount)->isZero()) {
                        $charges[] = ['label' => $label, 'amount' => Money::of($item->amount), 'quantity' => $item->quantity, 'taxable' => $item->taxable, 'taxGroupId' => $item->taxGroupId];
                    } else {
                        $this->logger->error('The cart line type {type} kept a charge "{label}: {amount}" that is not a label of up to 100 characters, an amount and a quantity; it is left out.', ['type' => $type->id(), 'label' => $item->label, 'amount' => $item->amount]);
                    }
                    continue;
                }
                if (!$item instanceof KeptItem || $item->quantity < 1 || $item->quantity > Cart::MAX_QUANTITY) {
                    continue;
                }
                try {
                    $quote = $this->quotes->quote(new QuoteRequest($item->productId, $item->optionIds, $item->quantity, $cart->customerId()));
                } catch (NotFound|RuleViolation) {
                    continue;
                }
                if ($quote->forSale) {
                    $kept[] = ['productId' => $item->productId, 'optionIds' => array_values($item->optionIds), 'quantity' => $item->quantity];
                }
            }
            $cart->keepAddOn($type->id(), $label, $removable, $kept, $this->clock->now(), $charges);
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
