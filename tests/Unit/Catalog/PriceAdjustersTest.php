<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Pricing\PriceQuery;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderNoteView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class PriceAdjustersTest extends TestCase
{
    private TestShop $shop;
    private int $gold;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Mug');
        $mug = $this->shop->products->save(10, new SaveProduct('10', attributes: [new SaveAttribute('Finish', [new SaveOption('Plain'), new SaveOption('Gold', '2.5')])]));
        $this->gold = $mug->attributes[0]->options[1]->id;
    }

    public function testAdjustersChangeTheItemsOwnPriceAfterTheRulesOneAfterAnother(): void
    {
        $this->shop->priceRules->create(new SavePriceRule('Bulk', [new PriceTierData(10, 'percent', '10')]));
        $seen = [];
        $this->shop->extensions->adjusters[] = self::adjuster('wholesale', static function (PriceQuery $query) use (&$seen): ?string {
            $seen[] = [$query->productId, $query->quantity, $query->customerId, $query->price, $query->regularPrice, $query->currency];

            return $query->customerId === 7 ? '8' : null;
        });
        $this->shop->extensions->adjusters[] = self::adjuster('surcharge', static fn(PriceQuery $query): string => (string) ((float) $query->price + 1));

        self::assertSame('13.5', $this->quote(null, 1), 'the guest pays the price, one more, and the option');
        self::assertSame('11.5', $this->quote(7, 1), 'the wholesale customer pays 8, one more, and the option');
        self::assertSame('12.5', $this->quote(null, 10), 'the rule takes 10 % first: 9, one more, and the option');
        self::assertSame([10, 10, null, '9', '10', 'EUR'], $seen[2]);
    }

    public function testAPercentageOptionIsAShareOfThePriceTheShopperPays(): void
    {
        $this->shop->content->add(40, 'Ring');
        $ring = $this->shop->products->save(40, new SaveProduct('100', attributes: [new SaveAttribute('Metal', [new SaveOption('Silver'), new SaveOption('Gold', '10', adjustmentKind: 'percent')])]));
        $gold = $ring->attributes[0]->options[1]->id;
        $this->shop->priceRules->create(new SavePriceRule('Sale', [new PriceTierData(1, 'percent', '20')]));

        $quote = $this->shop->quotes->quote(new QuoteRequest(40, [$gold]));

        self::assertSame(['88', '8'], [$quote->unitPrice, $quote->options[0]->adjustment], 'gold adds 10 % of the 80 the rule leaves');
    }

    public function testPricesByQuantityCountEveryLineOfTheProduct(): void
    {
        $this->shop->priceRules->create(new SavePriceRule('Bulk', [new PriceTierData(10, 'percent', '10')]));
        $plain = $this->shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 5));
        $owner = new CartOwner($plain->newToken);
        $cart = $this->shop->carts->add($owner, new AddToCart(10, [$this->gold], 5))->cart;

        self::assertSame(['9', '11.5'], array_map(static fn($line): string => $line->unitPrice, $cart->lines), 'five plain and five gold mugs are ten mugs');
    }

    public function testTheProductPageAndListingsShowTheAdjustedPriceAndTiers(): void
    {
        $this->shop->priceRules->create(new SavePriceRule('Bulk', [new PriceTierData(10, 'percent', '10')]));
        $this->shop->extensions->adjusters[] = self::adjuster('half', static fn(PriceQuery $query): string => (string) ((float) $query->price / 2));

        $offer = $this->shop->catalog->offer(10);
        self::assertSame(['5', '10'], [$offer->price, $offer->regularPrice], 'shown against the regular price');
        self::assertSame([['min_quantity' => 1, 'price' => '5'], ['min_quantity' => 10, 'price' => '4.5']], $offer->tiers);
    }

    public function testAnAnswerThatIsNotAPriceIsIgnoredAndBelowZeroIsZero(): void
    {
        $this->shop->extensions->adjusters[] = self::adjuster('broken', static fn(): string => 'free');
        $this->shop->extensions->adjusters[] = self::adjuster('failing', static fn(): string => throw new \RuntimeException('ERP down'));
        self::assertSame('12.5', $this->quote(null, 1));
        self::assertCount(2, $this->shop->logger->records);

        $this->shop->extensions->adjusters[] = self::adjuster('generous', static fn(): string => '-3');
        self::assertSame('2.5', $this->quote(null, 1), 'the item is free, the option still costs');
    }

    public function testAnOrderPricedWithoutAFailingAdjusterTellsTheShopAndOneThatFailsClosedRefusesIt(): void
    {
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->content->add(30, 'E-book');
        $this->shop->products->save(30, new SaveProduct('8', 'virtual'));
        $this->shop->extensions->adjusters[] = self::adjuster('failing', static fn(): string => throw new \RuntimeException('ERP down'));

        $placed = $this->shop->checkout->placeOrder($this->cart(), $this->order());

        $notes = array_map(static fn(OrderNoteView $note): array => [$note->text, $note->forCustomer], $this->shop->orders->order($placed->number)->notes);
        self::assertContains(['The price adjuster failing failed for product 30; it was priced without it.', false], $notes);

        $this->shop->extensions->adjusters = [new readonly class implements PriceAdjuster, FailsClosed {
            public function id(): string
            {
                return 'price_list';
            }

            public function adjust(PriceQuery $query): ?string
            {
                throw new \RuntimeException('ERP down');
            }
        }];
        $orders = \count($this->shop->orderRecords->orders);
        try {
            $this->shop->checkout->placeOrder($this->cart(), $this->order());
            self::fail('prices the shop cannot do without');
        } catch (CheckoutRuleViolation $e) {
            self::assertSame('orillacart_prices_unavailable', $e->errorCode);
        }
        self::assertCount($orders, $this->shop->orderRecords->orders);
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(30))->newToken);
    }

    private function order(): PlaceOrder
    {
        return new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer');
    }

    private function quote(?int $customerId, int $quantity): string
    {
        return $this->shop->quotes->quote(new QuoteRequest(10, [$this->gold], $quantity, $customerId))->unitPrice;
    }

    /**
     * @param \Closure(PriceQuery): ?string $answer
     */
    private static function adjuster(string $id, \Closure $answer): PriceAdjuster
    {
        return new readonly class ($id, $answer) implements PriceAdjuster {
            /** @param \Closure(PriceQuery): ?string $answer */
            public function __construct(private string $id, private \Closure $answer) {}

            public function id(): string
            {
                return $this->id;
            }

            public function adjust(PriceQuery $query): ?string
            {
                return ($this->answer)($query);
            }
        };
    }
}
