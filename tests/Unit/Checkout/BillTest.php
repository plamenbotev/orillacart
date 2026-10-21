<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Checkout\Domain\Bill;
use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class BillTest extends TestCase
{
    public function testTheTaxIncludedInAnAmountComesOutAtEachRate(): void
    {
        self::assertSame(['2'], self::parts('12', [self::tax('20')], true));
        self::assertSame('10', (string) Tax::without(Money::of('12'), [self::tax('20')]));
        // 12.00 at 19 %: 1.9160 of VAT, 10.0840 before it.
        self::assertSame(['1.916'], self::parts('12', [self::tax('19')], true));
        // A country's 5 % and a state's 2.5 % add up to 7.5 % of 107.50.
        self::assertSame(['5', '2.5'], self::parts('107.5', [self::tax('5'), self::tax('2.5', key: 'b')], true));
        self::assertSame('9', (string) Tax::without(Money::of('9'), []));
    }

    public function testACompoundRateIsChargedOnTheTaxesBeforeIt(): void
    {
        $taxes = [self::tax('5', 'GST'), self::tax('9.975', 'QST', compound: true)];

        // 9.975 % of 105.
        self::assertSame(['5', '10.4738'], self::parts('100', $taxes));
        self::assertSame(['5', '10.4738'], self::parts('115.4738', $taxes, true));
        self::assertSame('100', (string) Tax::without(Money::of('115.4738'), $taxes));

        $bill = new Bill(2);
        $bill->addItem(Money::of('100'), $taxes);
        $bill->ship(Money::of('10'), $taxes);
        self::assertSame(['5.50', '11.52'], array_map(static fn($tax): string => $tax->total()->format(2), $bill->taxes()));
        self::assertSame('127.02', $bill->total()->format(2));
    }

    public function testEachRateIsRoundedOnceOverTheOrderUnlessEachLineIs(): void
    {
        // Three items of 0.10 at 25 %: 0.025 each, 0.075 over the order.
        $once = new Bill(2);
        $eachLine = new Bill(2, roundsEachLine: true);
        foreach ([$once, $eachLine] as $bill) {
            foreach ([1, 2, 3] as $line) {
                $bill->addItem(Money::of('0.1'), [self::tax('25')]);
            }
        }

        self::assertSame('0.08', $once->itemsTax()->format(2));
        self::assertSame(['0.03', '0.03', '0.02'], array_map(static fn(Money $tax): string => $tax->format(2), $once->lineTaxes()));
        self::assertSame('0.09', $eachLine->itemsTax()->format(2));
        self::assertSame(['0.03', '0.03', '0.03'], array_map(static fn(Money $tax): string => $tax->format(2), $eachLine->lineTaxes()));
        self::assertSame('0.39', $eachLine->total()->format(2));
    }

    /**
     * @param list<Tax> $taxes
     *
     * @return list<string>
     */
    private static function parts(string $amount, array $taxes, bool $included = false): array
    {
        return array_map(strval(...), Tax::parts(Money::of($amount), $taxes, $included));
    }

    private static function tax(string $percent, string $name = 'VAT', bool $compound = false, ?string $key = null): Tax
    {
        return new Tax($key ?? $name, $name, $percent, $compound);
    }
}
