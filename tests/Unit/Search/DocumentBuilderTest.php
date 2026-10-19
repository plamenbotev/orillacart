<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Search\Application\DocumentBuilder;
use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentTerm;
use OrillaCart\Search\Application\Dto\DocumentVariant;
use OrillaCart\Search\Application\Dto\ProductText;
use OrillaCart\Search\Application\ProductTexts;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderTest extends TestCase
{
    private TestShop $shop;
    private DocumentBuilder $builder;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $texts = new class implements ProductTexts {
            public function describe(array $productIds): array
            {
                $texts = [];
                foreach (array_intersect($productIds, [10, 20]) as $id) {
                    $texts[$id] = new ProductText("Product {$id}", 'Warm and soft.', 3, new \DateTimeImmutable('2030-01-01'), [new DocumentTerm(4, 'Clothing'), new DocumentTerm(5, 'Hoodies')], [new DocumentTerm(9, 'Nordwind')]);
                }

                return $texts;
            }
        };
        $this->builder = new DocumentBuilder($this->shop->catalog, $texts, $this->shop->priceHistory);
    }

    public function testAProductWithVariationsIsOneVariantEachWithItsOwnOptionsPriceAndStock(): void
    {
        $this->shop->content->add(10, 'Hoodie');
        $saved = $this->shop->products->save(10, new SaveProduct('40', sku: 'HOOD', featured: true, attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M')])]));
        [$small, $medium] = [$saved->attributes[0]->options[0]->id, $saved->attributes[0]->options[1]->id];
        $this->shop->products->addVariation(10, new SaveVariation([$small], '38', sku: 'HOOD-S', stockMode: 'always_available'));
        $this->shop->products->addVariation(10, new SaveVariation([$medium], '42', new SaveSale('35', until: new \DateTimeImmutable('2030-02-01')), stockMode: 'unavailable'));

        $document = $this->builder->build(10);

        self::assertNotNull($document);
        self::assertSame(['Product 10', 'Warm and soft.', ['HOOD', 'HOOD-S'], 3], [$document->title, $document->text, $document->skus, $document->menuOrder]);
        self::assertEquals([[new DocumentTerm(4, 'Clothing'), new DocumentTerm(5, 'Hoodies')], [new DocumentTerm(9, 'Nordwind')]], [$document->categories, $document->brands], 'the categories with their ancestors, as the site gives them');
        self::assertSame([], $document->options, 'the options are the variants\'');
        self::assertEquals([
            new DocumentVariant($document->variants[0]->id, '38', true, [new DocumentOption('Size', 'S')]),
            new DocumentVariant($document->variants[1]->id, '35', false, [new DocumentOption('Size', 'M')]),
        ], $document->variants);
        self::assertSame([true, true, '2030-02-01'], [$document->featured, $document->onSale, $document->priceChangesAt?->format('Y-m-d')], 'built again when the sale ends');
    }

    public function testAProductWithoutVariationsIsOneVariantAndItsOptionsAreTheWholeProducts(): void
    {
        $this->shop->content->add(20, 'Mug');
        $this->shop->products->save(20, new SaveProduct('10', stockMode: 'always_available', attributes: [new SaveAttribute('Finish', [new SaveOption('Plain'), new SaveOption('Gold', '2')])]));

        $document = $this->builder->build(20);

        self::assertNotNull($document);
        self::assertEquals([new DocumentVariant(20, '10', true)], $document->variants);
        self::assertEquals([new DocumentOption('Finish', 'Plain'), new DocumentOption('Finish', 'Gold')], $document->options);
        self::assertFalse($document->onSale);
        self::assertNull($this->builder->build(30), 'no content, no document');
    }
}
