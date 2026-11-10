<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\GiftCards;

use OrillaCart\GiftCards\Application\Dto\AdjustBalance;
use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\GiftCards\Domain\GiftCardKind;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class StoreCreditTest extends TestCase
{
    public function testTwoFirstCreditsAtOnceAddToTheOneStoreCredit(): void
    {
        $shop = new TestShop();
        $ada = $shop->accounts->add('ada@example.com', 'Ada');
        // Another request gives Ada her first credit just as this one does.
        $shop->giftCardRecords->beforeStoreCredit = static function () use ($shop, $ada): void {
            $credit = GiftCard::storeCredit($ada, 'EUR', $shop->clock->now());
            $credit->credit(Money::of('5'));
            $shop->giftCardRecords->save($credit);
        };

        $view = $shop->giftCards->adjustStoreCredit($ada, new AdjustBalance('20', 'Sorry for the late delivery'), 1);

        self::assertSame('25', $view->card->balance);
        self::assertCount(1, array_filter($shop->giftCardRecords->cards, static fn(GiftCard $card): bool => $card->kind() === GiftCardKind::StoreCredit));
    }
}
