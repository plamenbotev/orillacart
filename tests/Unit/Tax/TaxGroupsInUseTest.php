<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/** A tax group that products or shipping use stays until they use another: deleting it would untax them. */
final class TaxGroupsInUseTest extends TestCase
{
    public function testAGroupInUseIsNotDeletedAndSaysWhatUsesIt(): void
    {
        $shop = new TestShop();
        $standard = $shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $reduced = $shop->taxGroups->create(new SaveTaxGroup('Reduced'))->id;
        $shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $standard));
        $shop->content->add(10, 'Book');
        $shop->products->save(10, new SaveProduct('12', taxGroupId: $reduced));
        $carrier = $shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Books', '3', taxGroupId: $reduced), new SaveShippingRate('Parcels', '5')]));

        try {
            $shop->taxGroups->delete($reduced);
            self::fail('the book would sell with no VAT');
        } catch (RuleViolation $e) {
            self::assertSame(['orillacart_tax_group_in_use', '"Reduced" taxes 1 product, 1 shipping rate: choose another tax group for them before deleting it, or they would be taxed at the default group\'s rates without anyone choosing it.'], [$e->errorCode, $e->getMessage()]);
        }

        $shop->products->save(10, new SaveProduct('12'));
        $shop->carriers->update($carrier->id, new SaveCarrier('Post', rates: [new SaveShippingRate('Books', '3', id: $carrier->rates[0]->id), new SaveShippingRate('Parcels', '5', id: $carrier->rates[1]->id)]));
        $shop->taxGroups->delete($reduced);
        self::assertSame(['Standard'], array_column($shop->taxGroups->groups(), 'name'));
    }
}
