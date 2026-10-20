<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\Dto\VariationView;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class DuplicateProductTest extends TestCase
{
    public function testACopyIsADraftWithTheProductsPricesAndVariationsButNoSkusOrStock(): void
    {
        $shop = new TestShop();
        $room = $shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $shop->content->add(11, 'Hoodie');
        $hoodie = $shop->products->save(11, new SaveProduct('40', sku: 'HOOD', sale: new SaveSale('35'), weight: '0.4', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M', '2')], required: true)]));
        $medium = $shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[1]->id], '44', sku: 'HOOD-M'));
        $shop->stock->setLevels(StockItemRef::variation($medium->id), new SetStockLevels([new SetStockLevel($room, 5)]));

        $copy = $shop->products->duplicate(11);

        self::assertNotSame(11, $copy->id);
        self::assertSame(['Hoodie (copy)', false], [$copy->name, $shop->content->isPublished($copy->id)]);
        self::assertSame(['40', '35', null, '0.4'], [$copy->price, $copy->sale?->price, $copy->sku, $copy->weight]);
        self::assertSame(['Size', true, ['S', 'M']], [$copy->attributes[0]->name, $copy->attributes[0]->required, array_map(static fn($option): string => $option->name, $copy->attributes[0]->options)]);
        self::assertNotSame($hoodie->attributes[0]->options[1]->id, $copy->attributes[0]->options[1]->id, 'options of its own');
        self::assertSame([[[$copy->attributes[0]->options[1]->id], '44', null]], array_map(static fn(VariationView $variation): array => [$variation->optionIds, $variation->price, $variation->sku], $copy->variations));
        self::assertSame([], $shop->stock->levelsOf([StockItemRef::variation($copy->variations[0]->id)])["variation:{$copy->variations[0]->id}"] ?? []);
        self::assertSame('HOOD-M', $shop->products->product(11)->variations[0]->sku, 'the original keeps its own');
    }
}
