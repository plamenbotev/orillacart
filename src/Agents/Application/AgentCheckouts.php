<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

use OrillaCart\Agents\Domain\AgentCheckout;
use OrillaCart\Agents\Domain\AgentCheckoutRepository;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Checkout\Application\CheckoutService;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\I18n\Translations;
use Psr\Clock\ClockInterface;

/**
 * Shoppers' agents' checkout sessions in UCP's shape (design 75). A session is one of the shop's carts: the lines
 * the agent asks for are added as a shopper adds them, refused for the reasons the shop refuses them, and priced
 * by the shop's checkout summary at the address the agent gives. With no delegated payment, a session holding all
 * it needs asks for the buyer (requires_escalation): its continue_url hands the cart to their browser, at the
 * shop's checkout, where they pay as any customer does. The order placed from the cart completes it.
 *
 * @phpstan-type Answer array<string, mixed>
 */
final readonly class AgentCheckouts
{
    /** A simple product's id, or a variation's with its product's. */
    public const string ITEM = '/^prod_([1-9][0-9]{0,9})(?:_var_([1-9][0-9]{0,9}))?$/';
    /** The fields of an address UCP writes, and what the shop's checkout calls them. */
    /** What a session keeps of what the agent was shown, for once its cart is no longer the agent's to read. */
    private const array SHOWN = ['currency' => true, 'line_items' => true, 'totals' => true, 'fulfillment' => true];
    public const array ADDRESS = ['first_name' => 'first_name', 'last_name' => 'last_name', 'street_address' => 'street', 'extended_address' => 'street2', 'address_locality' => 'city', 'address_region' => 'state', 'postal_code' => 'postcode', 'address_country' => 'country', 'phone_number' => 'phone'];

    public function __construct(
        private AgentCheckoutRepository $sessions,
        private CartService $carts,
        private CheckoutService $checkout,
        private ShopCatalog $catalog,
        private Currencies $currencies,
        private OrderLinks $links,
        private AgentEndpoints $endpoints,
        private Translations $translations,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<mixed> $checkout as the agent sent it
     *
     * @return Answer the session, or an error envelope's body when nothing could be put in a cart
     *
     * @throws UcpError 400 for a request that is not a checkout
     */
    public function create(array $checkout, string $agent): array
    {
        $lines = self::lineItems($checkout);
        [$token, $messages] = $this->fill(null, $lines);
        $cartId = $token === null ? null : $this->carts->contents(new CartOwner($token))?->cartId;
        if ($token === null || $cartId === null) {
            return ['error' => true, 'messages' => $messages !== [] ? $messages : [self::message('error', 'item_unavailable', 'None of the items can be ordered.', 'unrecoverable')], 'continue_url' => $this->endpoints->shop()];
        }
        $session = new AgentCheckout(AgentCheckout::newId(), $cartId, $token, $agent, $this->clock->now());
        [$buyer, $address, $option] = self::details($checkout, [], null, null);
        $session->set($buyer, $address, $option, $this->clock->now());
        $this->sessions->save($session);

        return $this->answer($session, $messages);
    }

    /** @return Answer */
    public function get(string $id): array
    {
        return $this->answer($this->find($id));
    }

    /**
     * The whole session again (PUT): lines, buyer and fulfilment as given; what is left out is no longer set.
     *
     * @param array<mixed> $checkout
     *
     * @return Answer
     */
    public function update(string $id, array $checkout): array
    {
        $session = $this->find($id);
        if ($session->isFinal() || $session->status($this->clock->now()) === AgentCheckout::CANCELED) {
            return $this->answer($session, [self::message('error', 'checkout_closed', 'The checkout session is ' . $session->status($this->clock->now()) . '.', 'unrecoverable')]);
        }
        if ($session->isClaimed()) {
            return $this->answer($session, [self::message('error', 'checkout_closed', 'The buyer has this checkout in their account at the shop: it changes there.', 'requires_buyer_input')]);
        }
        $messages = [];
        if (\array_key_exists('line_items', $checkout)) {
            $this->carts->clear($session->cartId());
            [, $messages] = $this->fill($session->cartToken(), self::lineItems($checkout));
        }
        [$buyer, $address, $option] = self::details($checkout, $session->buyer(), $session->address(), $session->shippingOption());
        $session->set($buyer, $address, $option, $this->clock->now());
        $this->sessions->save($session);

        return $this->answer($session, $messages);
    }

    /**
     * No payment handler is declared: the buyer completes it at the shop's checkout. Asking to complete answers
     * where they do; an agent never pays for a person here.
     *
     * @return Answer
     */
    public function complete(string $id): array
    {
        return $this->answer($this->find($id));
    }

    /** @return Answer */
    public function cancel(string $id): array
    {
        $session = $this->find($id);
        if (!$session->isFinal() && $session->status($this->clock->now()) !== AgentCheckout::CANCELED) {
            $session->cancel($this->clock->now());
            if (!$session->isClaimed()) {
                // A claimed cart is the buyer's account's: the session ends, what they hold stays theirs.
                $this->carts->clear($session->cartId());
            }
            $this->sessions->save($session);
        }

        return $this->answer($session);
    }

    /**
     * The cart an open session hands the buyer's browser (its continue_url): null for one that is over or none,
     * and for one a customer claimed, whose cart is their account's (isWithBuyer): their checkout has it.
     */
    public function handover(string $id): ?string
    {
        $session = $this->sessions->find($id);

        return $session === null || $this->over($session) || $session->isClaimed() ? null : $session->cartToken();
    }

    /** An open session a customer logged in with: its link opens the checkout, the cart is their account's. */
    public function isWithBuyer(string $id): bool
    {
        $session = $this->sessions->find($id);

        return $session !== null && !$this->over($session) && $session->isClaimed();
    }

    /**
     * What a handed-over cart's checkout starts with: the buyer and address the agent gave.
     *
     * @return array{email: string, billing: array<string, string>}|null the address as the checkout form names its fields
     */
    public function given(int $cartId): ?array
    {
        $session = $this->sessions->forCart($cartId);
        if ($session === null || $this->over($session)) {
            return null;
        }
        $buyer = $session->buyer();
        $billing = [];
        foreach ($session->address() ?? [] as $field => $value) {
            $name = self::ADDRESS[$field] ?? null;
            if ($name !== null) {
                $billing[$name] = $value;
            }
        }
        if (isset($billing['street2'])) {
            $billing['street'] = trim(($billing['street'] ?? '') . ', ' . $billing['street2'], ', ');
            unset($billing['street2']);
        }
        $billing += array_filter(['first_name' => $buyer['first_name'] ?? null, 'last_name' => $buyer['last_name'] ?? null, 'phone' => $buyer['phone_number'] ?? null], is_string(...));
        if ($billing === [] && !isset($buyer['email'])) {
            return null;
        }

        return ['email' => $buyer['email'] ?? '', 'billing' => $billing];
    }

    private function over(AgentCheckout $session): bool
    {
        return \in_array($session->status($this->clock->now()), [AgentCheckout::COMPLETED, AgentCheckout::CANCELED], true);
    }

    /** Sessions a day past their end are forgotten: the cart and the order are the shop's to keep. */
    public function forgetOld(): int
    {
        return $this->sessions->forgetOlderThan($this->clock->now()->sub(new \DateInterval('P1D')));
    }

    /**
     * A customer logged in with a session's cart (CartClaimed): it follows the cart it became. What else that cart
     * holds is the customer's, so the agent goes on seeing the session as it last saw it, until the order.
     */
    public function claimed(int $fromCartId, int $intoCartId): void
    {
        $session = $this->sessions->forCart($fromCartId);
        if ($session !== null && !$session->isFinal() && !$session->isClaimed()) {
            $session->claim($intoCartId, $this->clock->now());
            $this->sessions->save($session);
        }
    }

    /** The order the buyer placed from a session's cart (CartOrdered). */
    public function ordered(int $cartId, int $orderId): void
    {
        $session = $this->sessions->forCart($cartId);
        if ($session !== null && !$session->isFinal()) {
            $session->ordered($orderId, $this->clock->now());
            $this->sessions->save($session);
        }
    }

    private function find(string $id): AgentCheckout
    {
        return $this->sessions->find($id) ?? throw UcpError::notFound();
    }

    /**
     * @param array<mixed> $checkout
     *
     * @return list<array{string, int}> item id and quantity
     *
     * @throws UcpError
     */
    private static function lineItems(array $checkout): array
    {
        $items = $checkout['line_items'] ?? null;
        if (!\is_array($items) || $items === [] || \count($items) > 100) {
            throw new UcpError(400, 'invalid_request', 'line_items holds 1 to 100 items, each { item: { id }, quantity }.');
        }
        $lines = [];
        foreach (array_values($items) as $item) {
            $itemId = \is_array($item) && \is_array($item['item'] ?? null) ? ($item['item']['id'] ?? null) : null;
            $quantity = \is_array($item) ? ($item['quantity'] ?? 1) : null;
            if (!\is_string($itemId) || !\is_int($quantity) || $quantity < 1 || $quantity > Cart::MAX_QUANTITY) {
                throw new UcpError(400, 'invalid_request', \sprintf('Each line item is { item: { id }, quantity } with a quantity of 1 to %d.', Cart::MAX_QUANTITY));
            }
            $lines[] = [$itemId, $quantity];
        }

        return $lines;
    }

    /**
     * Puts the lines in the cart as a shopper would; what the shop refuses is a message on that line.
     *
     * @param list<array{string, int}> $lines
     *
     * @return array{?string, list<array<string, string>>} the cart's token (a new cart's, when given none), and the messages
     */
    private function fill(#[\SensitiveParameter] ?string $token, array $lines): array
    {
        $messages = [];
        foreach ($lines as $index => [$itemId, $quantity]) {
            $path = '$.line_items[' . $index . ']';
            if (preg_match(self::ITEM, $itemId, $match) !== 1) {
                $messages[] = self::message('error', 'item_unavailable', \sprintf('"%s" is not an item of this shop.', $itemId), 'recoverable', $path);
                continue;
            }
            $productId = (int) $match[1];
            try {
                $optionIds = [];
                if (isset($match[2])) {
                    $variation = array_values(array_filter($this->catalog->offer($productId)->variations, static fn(OfferedVariation $v): bool => $v->id === (int) $match[2]))[0] ?? null;
                    if ($variation === null) {
                        $messages[] = self::message('error', 'item_unavailable', \sprintf('"%s" is not an item of this shop.', $itemId), 'recoverable', $path);
                        continue;
                    }
                    $optionIds = $variation->optionIds;
                }
                $result = $this->carts->add(new CartOwner($token), new AddToCart($productId, $optionIds, $quantity));
                $token = $result->newToken ?? $token;
            } catch (RuleViolation|NotFound $e) {
                $stock = !$e instanceof NotFound && str_contains($e->errorCode, 'stock');
                $messages[] = self::message('error', $stock ? 'out_of_stock' : 'item_unavailable', $this->translations->text($e->text), 'recoverable', $path);
            }
        }

        return [$token, $messages];
    }

    /**
     * The buyer and fulfilment the agent sent, over what the session held.
     *
     * @param array<mixed>                                                                           $checkout
     * @param array{email?: string, first_name?: string, last_name?: string, phone_number?: string} $buyer
     * @param array<string, string>|null                                                             $address
     *
     * @return array{array{email?: string, first_name?: string, last_name?: string, phone_number?: string}, array<string, string>|null, string|null}
     */
    private static function details(array $checkout, array $buyer, ?array $address, ?string $option): array
    {
        if (\is_array($checkout['buyer'] ?? null)) {
            $buyer = [];
            foreach (['email' => 254, 'first_name' => 100, 'last_name' => 100, 'phone_number' => 30] as $field => $longest) {
                $value = $checkout['buyer'][$field] ?? null;
                if (\is_string($value) && trim($value) !== '') {
                    $buyer[$field] = mb_substr(trim($value), 0, $longest);
                }
            }
            if (isset($buyer['email']) && filter_var($buyer['email'], \FILTER_VALIDATE_EMAIL) === false) {
                unset($buyer['email']);
            }
        }
        $fulfillment = $checkout['fulfillment'] ?? null;
        $methods = \is_array($fulfillment) && \is_array($fulfillment['methods'] ?? null) ? array_values($fulfillment['methods']) : [];
        $method = $methods[0] ?? null;
        if (\is_array($fulfillment)) {
            $address = null;
            $option = null;
            if (\is_array($method)) {
                $destinations = \is_array($method['destinations'] ?? null) ? array_values($method['destinations']) : [];
                $chosen = $method['selected_destination_id'] ?? null;
                $destination = array_values(array_filter($destinations, static fn(mixed $d): bool => \is_array($d) && ($chosen === null || ($d['id'] ?? null) === $chosen)))[0] ?? null;
                $address = \is_array($destination) ? self::address($destination) : null;
                $groups = \is_array($method['groups'] ?? null) ? array_values($method['groups']) : [];
                $selected = \is_array($groups[0] ?? null) ? ($groups[0]['selected_option_id'] ?? null) : null;
                $option = \is_string($selected) && preg_match('/^[a-z0-9_:.\-]{1,120}$/i', $selected) === 1 ? $selected : null;
            }
        }
        /** @var array{email?: string, first_name?: string, last_name?: string, phone_number?: string} $buyer */
        return [$buyer, $address, $option];
    }

    /**
     * @param array<mixed> $destination
     *
     * @return array<string, string>|null
     */
    private static function address(array $destination): ?array
    {
        $address = [];
        foreach (array_keys(self::ADDRESS) as $field) {
            $value = $destination[$field] ?? null;
            if (\is_string($value) && trim($value) !== '') {
                $address[$field] = mb_substr(trim($value), 0, 200);
            }
        }
        $country = strtoupper($address['address_country'] ?? '');
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }
        $address['address_country'] = $country;
        if (isset($address['address_region'])) {
            $address['address_region'] = strtoupper($address['address_region']);
        }

        return $address;
    }

    /**
     * The session as UCP shows it, its status worked out from what it holds.
     *
     * @param list<array<string, string>> $messages what this change met
     *
     * @return Answer
     */
    private function answer(AgentCheckout $session, array $messages = []): array
    {
        $now = $this->clock->now();
        if ($session->isClaimed()) {
            return $this->claimedAnswer($session, $messages);
        }
        $owner = new CartOwner($session->cartToken());
        $address = $session->address();
        $view = null;
        $status = $session->status($now);
        if (!\in_array($status, [AgentCheckout::COMPLETED, AgentCheckout::CANCELED], true)) {
            try {
                $view = $this->checkout->summary($owner, new CheckoutDetails(
                    $address['address_country'] ?? null,
                    self::region($address),
                    $address['address_country'] ?? null,
                    self::region($address),
                    $session->shippingOption(),
                    $address['postal_code'] ?? null,
                    billingPostcode: $address['postal_code'] ?? null,
                    shippingCity: $address['address_locality'] ?? null,
                    billingCity: $address['address_locality'] ?? null,
                ));
            } catch (RuleViolation $e) {
                $messages[] = self::message('error', 'checkout_unavailable', $this->translations->text($e->text), 'recoverable', '$.line_items');
            }
        }
        $cart = $this->carts->cart($owner)->cart;
        $currency = $view->currency ?? $cart->currency ?? $this->currencies->current();
        $lines = [];
        foreach ($cart->lines as $index => $line) {
            $lines[] = $this->line($line, $view?->lines[$index] ?? null, $currency);
            if ($line->problem !== null) {
                $messages[] = self::message($line->problem === CartLineView::CHANGED ? 'warning' : 'error', match ($line->problem) {
                    CartLineView::INSUFFICIENT_STOCK => 'out_of_stock',
                    CartLineView::CHANGED => 'quantity_adjusted',
                    default => 'item_unavailable',
                }, $line->message ?? $line->name, 'recoverable', '$.line_items[' . $index . ']');
            }
        }
        $buyer = $session->buyer();
        if ($status !== AgentCheckout::COMPLETED && $status !== AgentCheckout::CANCELED) {
            $status = $this->status($session, $view, $lines, $messages);
            $session->become($status, $now);
        }

        $answer = [
            'id' => $session->id(),
            'status' => $status,
            'currency' => $currency,
            'line_items' => $lines,
            'totals' => $view === null ? [] : $this->totals($view),
            'messages' => $messages,
            'links' => [],
            'expires_at' => $session->expiresAt()->format(\DATE_ATOM),
        ];
        if ($buyer !== []) {
            $answer['buyer'] = $buyer;
        }
        if ($view !== null && $view->needsShipping) {
            $answer['fulfillment'] = $this->fulfillment($session, $view, $lines, $currency);
        }
        $session->show(array_intersect_key($answer, self::SHOWN));
        $this->sessions->save($session);
        if ($status === AgentCheckout::COMPLETED && $session->orderId() !== null) {
            $answer['order'] = ['id' => (string) $session->orderId(), 'permalink_url' => $this->links->receiptUrl($session->orderId())];
        } elseif ($status !== AgentCheckout::CANCELED) {
            $answer['continue_url'] = $this->endpoints->continueUrl($session->id());
        }

        return $answer;
    }

    /**
     * A session whose cart a customer claimed, as the agent last saw it: the cart is their account's now, and what
     * else it holds is not the agent's to read. It waits for the buyer, who orders at the shop's checkout.
     *
     * @param list<array<string, string>> $messages
     *
     * @return Answer
     */
    private function claimedAnswer(AgentCheckout $session, array $messages): array
    {
        $status = $session->status($this->clock->now());
        $answer = ['id' => $session->id(), 'status' => $status] + ($session->shown() ?? ['currency' => $this->currencies->current(), 'line_items' => [], 'totals' => []]);
        if (!\in_array($status, [AgentCheckout::COMPLETED, AgentCheckout::CANCELED], true)) {
            $answer['status'] = AgentCheckout::REQUIRES_ESCALATION;
            $messages[] = self::message('error', 'payment_required', 'The buyer has this checkout in their account at the shop, where they review and pay.', 'requires_buyer_input');
        }
        $answer += ['messages' => $messages, 'links' => [], 'expires_at' => $session->expiresAt()->format(\DATE_ATOM)];
        if ($session->buyer() !== []) {
            $answer['buyer'] = $session->buyer();
        }
        if ($status === AgentCheckout::COMPLETED && $session->orderId() !== null) {
            $answer['order'] = ['id' => (string) $session->orderId(), 'permalink_url' => $this->links->receiptUrl($session->orderId())];
        } elseif ($status !== AgentCheckout::CANCELED) {
            $answer['continue_url'] = $this->endpoints->continueUrl($session->id());
        }

        return $answer;
    }

    /**
     * Incomplete while something the API can collect is missing; then the buyer's, at the shop's checkout.
     *
     * @param list<array<string, mixed>>  $lines
     * @param list<array<string, string>> $messages
     */
    private function status(AgentCheckout $session, ?CheckoutView $view, array $lines, array &$messages): string
    {
        $missing = $view === null || $lines === [];
        if (($session->buyer()['email'] ?? null) === null) {
            $messages[] = self::message('error', 'field_required', 'The buyer\'s email is required.', 'recoverable', '$.buyer.email');
            $missing = true;
        }
        if ($view !== null && $view->needsShipping) {
            if ($session->address() === null) {
                $messages[] = self::message('error', 'field_required', 'Where it ships is required: an address with its country.', 'recoverable', '$.fulfillment.methods[0].destinations');
                $missing = true;
            } elseif ($session->shippingOption() === null || !\in_array($session->shippingOption(), array_map(static fn(ShippingChoiceView $o): string => $o->id, $view->shippingOptions), true)) {
                $messages[] = self::message('error', 'field_required', $view->shippingOptions === [] ? 'The shop does not ship there.' : 'Choose one of the ways to ship.', 'recoverable', '$.fulfillment.methods[0].groups[0].selected_option_id');
                $missing = true;
            }
        }
        foreach ($messages as $message) {
            $missing = $missing || ($message['type'] === 'error' && $message['severity'] === 'recoverable');
        }
        if ($missing) {
            return AgentCheckout::INCOMPLETE;
        }
        $messages[] = self::message('error', 'payment_required', 'The buyer pays at the shop\'s checkout: open continue_url for them to review and pay.', 'requires_buyer_input');

        return AgentCheckout::REQUIRES_ESCALATION;
    }

    /**
     * @param array<string, string>|null $address
     */
    private static function region(?array $address): ?string
    {
        $region = $address['address_region'] ?? null;

        return $region !== null && preg_match('/^[A-Z0-9]{1,3}$/', $region) === 1 ? $region : null;
    }

    /** @return array<string, mixed> */
    private function line(CartLineView $line, ?\OrillaCart\Checkout\Application\Dto\CheckoutLineView $priced, string $currency): array
    {
        $total = $this->minor($priced->total ?? $line->total, $currency);

        return [
            'id' => 'li_' . $line->id,
            'item' => ['id' => 'prod_' . $line->productId . ($line->variationId === null ? '' : '_var_' . $line->variationId), 'title' => $line->name, 'price' => $this->minor($priced->unitPrice ?? $line->unitPrice, $currency)],
            'quantity' => $line->quantity,
            'totals' => [['type' => 'subtotal', 'amount' => $total], ['type' => 'total', 'amount' => $total]],
        ];
    }

    /** @return list<array{type: string, amount: int}> */
    private function totals(CheckoutView $view): array
    {
        $tax = Money::of($view->itemsTax)->add(Money::of($view->shippingTax))->add(Money::of($view->feesTax));
        $fees = Money::zero();
        foreach ($view->fees as $fee) {
            $fees = $fees->add(Money::of($fee->amount));
        }
        foreach ($view->paymentFees as $gateway => $fee) {
            if ($gateway === $view->paymentMethod) {
                $fees = $fees->add(Money::of($fee));
            }
        }
        $totals = [['type' => 'subtotal', 'amount' => $this->minor($view->subtotal, $view->currency)]];
        if (!Money::of($view->discount)->isZero()) {
            $totals[] = ['type' => 'discount', 'amount' => $this->minor($view->discount, $view->currency)];
        }
        if ($view->needsShipping) {
            $totals[] = ['type' => 'fulfillment', 'amount' => $this->minor($view->shipping, $view->currency)];
        }
        if (!$fees->isZero()) {
            $totals[] = ['type' => 'fee', 'amount' => $this->minor($fees->toFixed(), $view->currency)];
        }
        $totals[] = ['type' => 'tax', 'amount' => $this->minor($tax->toFixed(), $view->currency)];
        $totals[] = ['type' => 'total', 'amount' => $this->minor($view->total, $view->currency)];

        return $totals;
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function fulfillment(AgentCheckout $session, CheckoutView $view, array $lines, string $currency): array
    {
        $ids = array_column($lines, 'id');
        $address = $session->address();
        $method = ['id' => 'shipping', 'type' => 'shipping', 'line_item_ids' => $ids, 'destinations' => $address === null ? [] : [['id' => 'dest_1'] + $address]];
        if ($address !== null) {
            $method['selected_destination_id'] = 'dest_1';
            $group = [
                'id' => 'group_1',
                'line_item_ids' => $ids,
                'options' => array_map(fn(ShippingChoiceView $o): array => ['id' => $o->id, 'title' => $o->name, 'description' => $o->carrier, 'totals' => [['type' => 'total', 'amount' => $this->minor($o->total, $currency)]]], $view->shippingOptions),
            ];
            if ($session->shippingOption() !== null) {
                $group['selected_option_id'] = $session->shippingOption();
            }
            $method['groups'] = [$group];
        }

        return ['methods' => [$method]];
    }

    /** An amount in the currency's smallest unit, as UCP counts it. */
    private function minor(string $amount, string $currency): int
    {
        return (int) str_replace(['.', '-'], '', Money::of($amount)->format($this->currencies->decimals($currency))) * (str_starts_with(trim($amount), '-') ? -1 : 1);
    }

    /** @return array<string, string> */
    private static function message(string $type, string $code, string $content, string $severity, ?string $path = null): array
    {
        return ['type' => $type, 'code' => $code, 'content' => $content, 'severity' => $severity] + ($path === null ? [] : ['path' => $path]);
    }
}
