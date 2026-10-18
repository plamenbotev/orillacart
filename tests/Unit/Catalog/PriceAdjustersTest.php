<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Pricing\PriceQuery;
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
