<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\ProductSpecifications;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The product page's specifications: the attributes the shop lists there, in the shopper's language, and the
 * product's weight and size.
 */
final class ProductSpecificationsTest extends TestCase
{
    private TestShop $shop;
    private ProductSpecifications $specifications;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-31 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $this->shop->content->add(10, 'Shirt');
        $this->specifications = new ProductSpecifications($this->shop->productRecords, $this->shop->productTranslations, $this->shop->attributeNames, $this->shop->settingsService);
    }

    public function testTheAttributesTheShopListsThereWithTheProductsMeasures(): void
    {
        $this->shop->products->save(10, new SaveProduct('20', weight: '0.35', length: '30', width: '20', height: '2', attributes: [
            new SaveAttribute('Colour', [new SaveOption('Red', translations: ['de_DE' => 'Rot']), new SaveOption('Blue')], translations: ['de_DE' => 'Farbe']),
            new SaveAttribute('Gift wrap', [new SaveOption('Yes', '3')], inSpecifications: false),
        ]));

        $shirt = $this->specifications->of(10);
        self::assertNotNull($shirt);
        self::assertSame([['name' => 'Colour', 'values' => ['Red', 'Blue']]], $shirt->attributes, 'not the gift wrap');
        self::assertSame(['0.35', '30', '20', '2', 'kg', 'cm', true], [$shirt->weight, $shirt->length, $shirt->width, $shirt->height, $shirt->weightUnit, $shirt->dimensionUnit, $shirt->ships]);

        $this->shop->languageOfRequest->locale = 'de_DE';
        self::assertSame([['name' => 'Farbe', 'values' => ['Rot', 'Blue']]], new ProductSpecifications($this->shop->productRecords, $this->shop->productTranslations, $this->shop->attributeNames, $this->shop->settingsService)->of(10)?->attributes, 'in the shopper\'s language');
        self::assertNull($this->specifications->of(99), 'no commerce details');
    }

    public function testASaveThatLeavesTheAttributesAsTheyAreKeepsWhatTheSpecificationsList(): void
    {
        $this->shop->products->save(10, new SaveProduct('20', attributes: [new SaveAttribute('Gift wrap', [new SaveOption('Yes', '3')], inSpecifications: false)]));

        $this->shop->products->save(10, SaveProduct::unchanged($this->shop->products->product(10), ['price' => '25']));

        self::assertFalse($this->shop->products->product(10)->attributes[0]->inSpecifications);
        self::assertFalse($this->shop->catalog->offer(10)->attributes[0]->inSpecifications);
        $copy = $this->shop->products->duplicate(10);
        self::assertFalse($copy->attributes[0]->inSpecifications, 'nor a copy');
    }
}
