<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\ProductChoice;
use OrillaCart\Catalog\Application\Dto\ProductChoiceQuery;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveBundle;
use OrillaCart\Catalog\Application\Dto\SaveBundleItem;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductChoices;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The catalog keeps what it sells sellable: a bundle's items stay as the bundle holds them, a SKU names one item,
 * and copies and pickers take the product, not a translation's post or a draft one may not see.
 */
final class CatalogGuardsTest extends TestCase
{
    private TestShop $shop;
    private int $small;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sku: 'LAMP'));
        $this->shop->content->add(11, 'Hoodie');
        $hoodie = $this->shop->products->save(11, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M')])]));
        $this->small = $this->shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[0]->id], '41'))->id;
        $this->shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[1]->id], '42'));
        $this->shop->content->add(30, 'Gift box');
        $this->shop->products->save(30, new SaveProduct('60', bundle: new SaveBundle([new SaveBundleItem(10, 1), new SaveBundleItem(11, 1, [$this->small])])));
    }

    public function testAnItemABundleHoldsStaysAsTheBundleHoldsIt(): void
    {
        $lamp = $this->shop->products->product(10);
        $hoodie = $this->shop->products->product(11);
        foreach ([
            'becomes a bundle' => fn() => $this->shop->products->save(10, SaveProduct::unchanged($lamp, ['bundle' => new SaveBundle([new SaveBundleItem(11, 1, [$this->small])])])),
            'asks to choose' => fn() => $this->shop->products->save(10, SaveProduct::unchanged($lamp, ['attributes' => [new SaveAttribute('Colour', [new SaveOption('Red')], required: true)]])),
            'gains variations' => fn() => $this->shop->products->addVariation(10, new SaveVariation([], '30')),
            'loses the bundled variation' => fn() => $this->shop->products->removeVariation(11, $this->small),
        ] as $change => $refused) {
            try {
                $refused();
                self::fail("Refused: it {$change}.");
            } catch (CatalogRuleViolation $e) {
                self::assertSame('orillacart_bundled_item', $e->errorCode, $change);
                self::assertStringContainsString('"Gift box"', (string) $e->text, "the bundles are named: {$change}");
            }
        }

        $this->shop->products->removeVariation(11, $hoodie->variations[1]->id);
        self::assertCount(1, $this->shop->products->product(11)->variations, 'a variation the bundle does not hold may go');
        self::assertSame('35', $this->shop->products->save(10, SaveProduct::unchanged($lamp, ['price' => '35']))->price, 'other changes are saved');
    }

    public function testAVariationsSkuIsCheckedAgainstEveryProductItsOwnToo(): void
    {
        $this->shop->products->save(11, SaveProduct::unchanged($this->shop->products->product(11), ['sku' => 'HOOD']));
        $this->expectException(CatalogRuleViolation::class);
        $this->expectExceptionMessage('HOOD');

        $this->shop->products->addVariation(11, new SaveVariation([], '40', sku: 'HOOD'));
    }

    public function testDuplicatingATranslationsRowCopiesTheProductAndItsVariationsPictures(): void
    {
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $this->shop->content->add(12, 'Kapuzenpulli');
        $this->shop->contentLanguages->translate(['en' => 11, 'de' => 12]);

        $copy = $this->shop->products->duplicate(12);

        self::assertSame('Hoodie (copy)', $copy->name, 'the original copied, not the German post');
        self::assertCount(2, $copy->variations);
        self::assertSame(array_map(static fn($variation): int => $variation->id, $this->shop->products->product(11)->variations), array_values($this->shop->content->copiedVariations), 'each variation\'s texts and pictures copied');
    }

    public function testDraftProductsArePickedOnlyByThoseWhoMayEditThem(): void
    {
        $this->shop->content->add(13, 'Lamp shade', published: false);
        $choices = new ProductChoices($this->shop->content, $this->shop->productTranslations);

        self::assertEquals([new ProductChoice(13, 'Lamp shade'), new ProductChoice(10, 'Lamp')], $choices->find(new ProductChoiceQuery(search: 'lamp')));
        self::assertEquals([new ProductChoice(10, 'Lamp')], $choices->find(new ProductChoiceQuery(search: 'lamp'), publishedOnly: true));
        self::assertEquals([new ProductChoice(10, 'Lamp')], $choices->find(new ProductChoiceQuery(include: '13,10'), publishedOnly: true));
    }
}
