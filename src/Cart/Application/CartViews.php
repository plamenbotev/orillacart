<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application;

use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartView;
use OrillaCart\Cart\Domain\AddOnLine;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Cart\Domain\CartLine;
use OrillaCart\Cart\Domain\ChargeLine;
use OrillaCart\Cart\Domain\GivenLine;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Forms\Application\Dto\GivenAnswer;
use OrillaCart\Forms\Application\FieldAnswers;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\ClosedFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * A cart as the shopper and checkout see it: each line quoted at today's price, with its answers and files, what
 * the cart rules refuse, and the totals. Reading a cart changes nothing here.
 */
final readonly class CartViews
{
    public function __construct(
        private ProductQuotes $quotes,
        private SettingsService $settings,
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private Currencies $currencies,
        private ShopForms $forms,
        private ProductContent $content,
        private ClosedFailures $closedFailures,
    ) {}

    public function contents(Cart $cart): ?CartContents
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
                $charges[] = ['label' => $line->label(), 'amount' => (string) $line->amount(), 'quantity' => $line->quantity(), 'taxable' => $line->taxable(), 'tax_group_id' => $line->taxGroupId(), 'source' => Cart::ADD_ON . $line->addOnType(), 'for_product_ids' => $line->forProductIds()];
                continue;
            }
            $answers = $this->answersOf($line);
            if ($answers === null) {
                $refusals[] = \sprintf('%s: %s', $this->content->name($line->productId()), CartService::CHANGED);
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

    public function view(Cart $cart): CartView
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
                    CartLineView::CHANGED => CartService::CHANGED,
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

    /**
     * The line's answers checked against the product's forms as they are now; null when they no longer hold (a
     * required question added, a choice or a file gone).
     *
     * @return list<GivenAnswer>|null
     */
    public function answersOf(CartLine $line): ?array
    {
        try {
            return $this->forms->productAnswers($line->productId(), $this->content->termIds($line->productId())['categories'], $line->answers(), kept: true);
        } catch (InvalidFields) {
            return null;
        }
    }

    /**
     * What the add-ons' cart rules refuse: messages by line, and those about the whole cart; and whether a rule
     * the shop cannot do without failed. A rule that fails is logged and left out, so one broken add-on does not
     * stop every cart; one that fails closed (FailsClosed: an age check, a licence, an export limit) refuses the
     * whole cart for now instead, and the shop manager hears of it on Home and the Status screen (ClosedFailures).
     *
     * @return array{array<int, string>, list<string>, bool}
     */
    public function refusals(CheckedCart $cart): array
    {
        $lines = [];
        $whole = [];
        $closed = false;
        foreach ($this->extensions->cartRules() as $rule) {
            try {
                $refusals = $rule->check($cart);
            } catch (\Throwable $e) {
                $this->logger->error('The cart rule {rule} failed: {message}', ['rule' => $rule->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                if ($rule instanceof FailsClosed) {
                    $closed = true;
                    $whole[] = CartService::UNCHECKED;
                    $this->closedFailures->failed('cart_rule:' . $rule->id(), \sprintf('The cart rule %s failed (%s): no cart can be ordered until it answers.', $rule->id(), mb_substr($e->getMessage(), 0, 200)));
                }
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

        return [$lines, array_values(array_unique($whole)), $closed];
    }

    public static function checkedLine(CartLine $line, ItemQuote $quote): CheckedLine
    {
        return new CheckedLine(self::lineKey($line), $quote->productId, $quote->variationId, $quote->sku, $quote->name, $line->quantity(), $quote->unitPrice);
    }

    /** A line not saved yet, as one being added, is 0 to the rules. */
    public static function lineKey(CartLine $line): int
    {
        return $line->isSaved() ? $line->id() : 0;
    }
}
