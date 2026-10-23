<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Shipping taxed as the items it carries: its price shared out by what they cost, each share at their rates.
 */
final class ShippingTaxedAsItemsTest extends TestCase
{
    private TestShop $shop;
    private CartOwner $owner;
    private int $standard;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->standard = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $books = $this->shop->taxGroups->create(new SaveTaxGroup('Books'))->id;
        $this->shop->taxGroups->addRate($this->standard, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxGroups->addRate($books, new SaveTaxRate('BG', null, '9', 'VAT reduced'));
        $this->settings(false);
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '1'));
        $this->shop->content->add(11, 'Atlas');
        $this->shop->products->save(11, new SaveProduct('10', weight: '1', taxGroupId: $books));
        $this->shop->content->add(12, 'Gift voucher');
        $this->shop->products->save(12, new SaveProduct('20', weight: '1', taxable: false));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Everywhere', '8')]));
        $this->owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $this->shop->carts->add($this->owner, new AddToCart(11));
    }

    public function testShippingIsTaxedAtTheRatesOfWhatItCarries(): void
    {
        self::assertSame([['VAT', '1.6']], $this->shippingTaxes(), 'at its own group by default');

        $this->settings(true);
        // 8 shared 30 : 10: 6 at 20 %, 2 at 9 %.
        self::assertSame([['VAT', '1.2'], ['VAT reduced', '0.18']], $this->shippingTaxes());
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'));
        self::assertSame(['8', '1.38', '1.38'], [$summary->shipping, $summary->shippingTax, $summary->shippingOptions[0]->tax]);
        self::assertSame('56.28', $summary->total, '40 + 6.9 items tax + 8 + 1.38');
    }

    public function testTheShareOfUntaxedItemsIsUntaxed(): void
    {
        $this->settings(true);
        $this->shop->carts->add($this->owner, new AddToCart(12));

        // 8 shared 30 : 10 : 20: 4 at 20 %, 1.33 at 9 %, 2.67 untaxed.
        self::assertSame([['VAT', '0.8'], ['VAT reduced', '0.12']], $this->shippingTaxes());
    }

    public function testWithPricesIncludingTaxTheTaxIsInEachShare(): void
    {
        $this->settings(true, includesTax: true);

        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'));

        // 6 holds 1 of 20 %; 2 holds 0.1651 of 9 %.
        self::assertSame(['8', [['VAT', '1'], ['VAT reduced', '0.17']]], [$summary->shipping, $this->shippingTaxes()]);
    }

    public function testARateThatLeavesShippingAloneIsNotChargedOnIt(): void
    {
        $this->shop->taxGroups->addRate($this->standard, new SaveTaxRate('US', 'NY', '4', 'NY', shipping: false));

        self::assertSame(['0', '1.2'], $this->taxedInNewYork(), 'the lamp at 4 %; the atlas\'s group has no rate there');
        $this->settings(true);
        self::assertSame(['0', '1.2'], $this->taxedInNewYork(), 'nor on the items\' share of it');
    }

    /** @return array{string, string} the shipping's tax and the items' */
    private function taxedInNewYork(): array
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('US', 'NY'));

        return [$summary->shippingTax, $summary->itemsTax];
    }

    private function settings(bool $asItems, bool $includesTax = false): void
    {
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $this->standard, $includesTax, shippingTaxedAsItems: $asItems));
    }

    /** @return list<array{string, string}> */
    private function shippingTaxes(): array
    {
        $summary = $this->shop->checkout->summary($this->owner, new CheckoutDetails('BG'));

        return array_values(array_map(static fn($tax): array => [$tax->name, $tax->shipping], array_filter($summary->taxes, static fn($tax): bool => $tax->shipping !== '0')));
    }
}
