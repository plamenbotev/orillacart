<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Domain\AdjustmentKind;
use OrillaCart\Catalog\Domain\AttributeDraft;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\OptionDraft;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductDetails;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\Sale;
use OrillaCart\Catalog\Domain\VariationDetails;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Catalog\CatalogIds;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryAttributeSetRepository;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryProductRepository $products;
    private InMemoryAttributeSetRepository $sets;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-17 12:00:00 UTC');
        $ids = new CatalogIds();
        $this->products = new InMemoryProductRepository($ids);
        $this->sets = new InMemoryAttributeSetRepository($ids);
    }

    public function testASaleRunsBetweenItsDatesAndEitherEndMayBeOpen(): void
    {
        $sale = static fn(?string $from, ?string $until): Pricing => new Pricing(Money::of('20'), new Sale(
            Money::of('15'),
            $from === null ? null : new \DateTimeImmutable($from),
            $until === null ? null : new \DateTimeImmutable($until),
        ));

        self::assertSame('15', (string) $sale('2026-10-17 11:00 UTC', '2026-10-17 13:00 UTC')->priceAt($this->now));
        self::assertSame('20', (string) $sale('2026-10-17 12:00:01 UTC', null)->priceAt($this->now), 'not started');
        self::assertSame('20', (string) $sale(null, '2026-10-17 12:00 UTC')->priceAt($this->now), 'ends at its end');
        self::assertSame('15', (string) $sale('2026-10-17 12:00 UTC', null)->priceAt($this->now), 'starts at its start');
        self::assertSame('15', (string) $sale(null, null)->priceAt($this->now));
    }

    public function testASaleEndsAfterItStarts(): void
    {
        $this->expectException(CatalogRuleViolation::class);

        new Sale(Money::of('1'), new \DateTimeImmutable('2026-10-18'), new \DateTimeImmutable('2026-10-17'));
    }

    public function testChosenOptionsAdjustThePriceButNeverBelowZero(): void
    {
        $product = $this->product('10', [
            new AttributeDraft(null, 'Colour', [self::option('Red', '0'), self::option('Gold', '25')]),
            new AttributeDraft(null, 'Discount', [self::option('Big', '-30')]),
        ]);
        [$red, $gold] = $this->optionIds($product, 'Colour');
        [$big] = $this->optionIds($product, 'Discount');

        self::assertSame('10', (string) $product->configure([], $this->now)->unitPrice);
        self::assertSame('35', (string) $product->configure([$gold], $this->now)->unitPrice);
        self::assertSame('0', (string) $product->configure([$red, $big], $this->now)->unitPrice);
        self::assertSame('10', (string) $product->configure([$red, $red], $this->now)->unitPrice, 'a repeated option counts once');
    }

    public function testAPercentageIsOfTheProductPriceOnSaleAndDoesNotBuildOnOtherOptions(): void
    {
        $product = $this->product('80', [
            new AttributeDraft(null, 'Frame', [new OptionDraft(null, 'Oak', Money::of('15'), AdjustmentKind::Percent), self::option('Pine')]),
            new AttributeDraft(null, 'Glass', [new OptionDraft(null, 'Plain', Money::of('-10'), AdjustmentKind::Percent), self::option('Museum', '20')]),
        ]);
        [$oak] = $this->optionIds($product, 'Frame');
        [$plain, $museum] = $this->optionIds($product, 'Glass');

        self::assertSame('92', (string) $product->configure([$oak], $this->now)->unitPrice, '80 + 15 %');
        self::assertSame('84', (string) $product->configure([$oak, $plain], $this->now)->unitPrice, '80 + 12 - 8: each percentage of 80');
        self::assertSame('112', (string) $product->configure([$oak, $museum], $this->now)->unitPrice);

        $product->change(new ProductDetails(ProductType::Physical, new Pricing(Money::of('80'), new Sale(Money::of('60')))));
        self::assertSame('69', (string) $product->configure([$oak], $this->now)->unitPrice, 'of the sale price');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function percentagesOutOfRange(): iterable
    {
        yield 'takes off more than the price' => ['-100.0001'];
        yield 'too large' => ['1000'];
    }

    #[DataProvider('percentagesOutOfRange')]
    public function testAPercentageStaysInRange(string $percent): void
    {
        $this->expectException(CatalogRuleViolation::class);
        new OptionDraft(null, 'Wrong', Money::of($percent), AdjustmentKind::Percent);
    }

    public function testEveryRequiredAttributeNeedsOneOption(): void
    {
        $product = $this->product('10', [new AttributeDraft(null, 'Size', [self::option('S'), self::option('M')], required: true)]);
        [$small, $medium] = $this->optionIds($product, 'Size');

        self::assertSame('10', (string) $product->configure([$small], $this->now)->unitPrice);
        $this->assertRefused($product, [], 'Choose an option for "Size".');
        $this->assertRefused($product, [$small, $medium], 'Choose one option for "Size".');
    }

    public function testOptionsOfOtherProductsAndInactiveSetsAreRefused(): void
    {
        $set = $this->set('Engraving', [new AttributeDraft(null, 'Text', [self::option('Name', '10')], required: true)], active: false);
        $product = $this->product('9', [], [$set]);
        $other = $this->product('5', [new AttributeDraft(null, 'Colour', [self::option('Red')])], id: 2);

        self::assertSame('9', (string) $product->configure([], $this->now)->unitPrice, 'an inactive set\'s required attribute plays no part');
        $this->assertRefused($product, [$set->ownAttributes()[0]->options()[0]->id()], 'is not available');
        $this->assertRefused($product, $this->optionIds($other, 'Colour'), 'is not available');
    }

    public function testASetsAttributesComeBeforeTheProductsOwn(): void
    {
        $set = $this->set('Clothing', [new AttributeDraft(null, 'Size', [self::option('S')])]);
        $product = $this->product('1', [new AttributeDraft(null, 'Gift wrap', [self::option('Yes')])], [$set]);

        self::assertSame(['Size', 'Gift wrap'], array_map(static fn($attribute): string => $attribute->name(), $product->attributes()));
    }

    public function testAProductWithVariationsIsSoldOnlyAsOneOfThem(): void
    {
        $set = $this->set('Clothing', [
            new AttributeDraft(null, 'Size', [self::option('S'), self::option('M', '2'), self::option('L', '5')], required: true),
            new AttributeDraft(null, 'Gift wrap', [self::option('Yes', '3.5')]),
        ]);
        $product = $this->product('40', [], [$set]);
        [$small, $medium, $large] = array_map(static fn($option): int => $option->id(), $set->ownAttributes()[0]->options());
        $sale = new Sale(Money::of('35'), $this->now->modify('-1 day'), $this->now->modify('+1 day'));
        $smallVariation = $product->addVariation(901, new VariationDetails([$small], new Pricing(Money::of('38'))));
        $product->addVariation(902, new VariationDetails([$medium], new Pricing(Money::of('40'), $sale)));
        $this->products->save($product);

        $configuration = $product->configure([$small], $this->now);
        self::assertSame($smallVariation, $configuration->variation);
        self::assertSame('38', (string) $configuration->unitPrice, 'the variation\'s own price, without option adjustments');
        self::assertSame('35', (string) $product->configure([$medium], $this->now)->unitPrice);
        $this->assertRefused($product, [], 'not sold with this combination');
        $this->assertRefused($product, [$large], 'is not available', 'no variation uses it, so it is not offered');

        $offered = $product->offeredOptions();
        self::assertCount(1, $offered, 'gift wrap is not offered: no variation uses it');
        self::assertSame(['S', 'M'], array_map(static fn($option): string => $option->name(), $offered[0][1]));
    }

    public function testVariationsDifferInTheirOptions(): void
    {
        $product = $this->product('10', [
            new AttributeDraft(null, 'Size', [self::option('S'), self::option('M')]),
            new AttributeDraft(null, 'Colour', [self::option('Red')]),
        ]);
        [$small, $medium] = $this->optionIds($product, 'Size');
        [$red] = $this->optionIds($product, 'Colour');
        $product->addVariation(903, new VariationDetails([$small, $red], new Pricing(Money::of('11'))));
        $this->products->save($product);

        foreach ([
            'the same options' => [[$red, $small], 'already has these options'],
            'two sizes' => [[$small, $medium], 'one option for "Size"'],
            'an unknown option' => [[999], 'not one of this product\'s options'],
        ] as $label => [$options, $message]) {
            try {
                $product->addVariation(904, new VariationDetails($options, new Pricing(Money::of('1'))));
                self::fail("A variation with {$label} was added.");
            } catch (CatalogRuleViolation $e) {
                self::assertStringContainsString($message, $e->getMessage(), $label);
            }
        }
    }

    public function testOptionsAVariationUsesCannotBeRemoved(): void
    {
        $product = $this->product('10', [new AttributeDraft(null, 'Size', [self::option('S'), self::option('M')])]);
        [$small, $medium] = $this->optionIds($product, 'Size');
        $attributeId = $product->ownAttributes()[0]->id();
        $product->addVariation(905, new VariationDetails([$small], new Pricing(Money::of('11'))));
        $this->products->save($product);

        // Removing M is fine, renaming S too.
        $product->replaceAttributes([new AttributeDraft($attributeId, 'Size', [new OptionDraft($small, 'Small', Money::zero())])], []);
        self::assertSame(['Small'], array_map(static fn($option): string => $option->name(), $product->ownAttributes()[0]->options()));
        self::assertNotContains($medium, $product->ownAttributes()[0]->savedOptionIds());

        $this->expectException(CatalogRuleViolation::class);
        $this->expectExceptionMessage('A variation uses an option this would remove');
        $product->replaceAttributes([], []);
    }

    public function testAttributesAndOptionsKeepTheirIdsWhenChanged(): void
    {
        $product = $this->product('10', [new AttributeDraft(null, 'Size', [self::option('S'), self::option('M')])]);
        $attribute = $product->ownAttributes()[0];
        [$small, $medium] = $this->optionIds($product, 'Size');

        $product->replaceAttributes([
            new AttributeDraft(null, 'Colour', [self::option('Red')]),
            new AttributeDraft($attribute->id(), 'Size', [new OptionDraft($medium, 'M', Money::of('1')), self::option('L'), new OptionDraft($small, 'S', Money::zero())], required: true),
        ], []);
        $this->products->save($product);

        $size = $product->ownAttributes()[1];
        self::assertSame($attribute, $size);
        self::assertTrue($size->isRequired());
        self::assertSame(['M', 'L', 'S'], array_map(static fn($option): string => $option->name(), $size->options()));
        self::assertSame($medium, $size->options()[0]->id());
        self::assertSame('Colour', $product->ownAttributes()[0]->name());

        $this->expectException(CatalogRuleViolation::class);
        $product->replaceAttributes([new AttributeDraft(12345, 'Ghost', [self::option('Boo')])], []);
    }

    /**
     * @param list<AttributeDraft> $attributes
     * @param list<AttributeSet>   $sets
     */
    private function product(string $price, array $attributes, array $sets = [], int $id = 1): Product
    {
        $product = new Product($id, new ProductDetails(ProductType::Physical, new Pricing(Money::of($price))));
        $product->replaceAttributes($attributes, $sets);
        $this->products->save($product);

        return $product;
    }

    /**
     * @param list<AttributeDraft> $attributes
     */
    private function set(string $name, array $attributes, bool $active = true): AttributeSet
    {
        $set = new AttributeSet($name, $active);
        $set->replaceAttributes($attributes);
        $this->sets->save($set);

        return $set;
    }

    /**
     * @return list<int>
     */
    private function optionIds(Product $product, string $attribute): array
    {
        foreach ($product->attributes() as $candidate) {
            if ($candidate->name() === $attribute) {
                return $candidate->savedOptionIds();
            }
        }
        self::fail("No attribute {$attribute}.");
    }

    private static function option(string $name, string $adjustment = '0'): OptionDraft
    {
        return new OptionDraft(null, $name, Money::of($adjustment));
    }

    /**
     * @param list<int> $optionIds
     */
    private function assertRefused(Product $product, array $optionIds, string $message, string $why = ''): void
    {
        try {
            $product->configure($optionIds, $this->now);
            self::fail('The choice was accepted. ' . $why);
        } catch (InvalidSelection $e) {
            self::assertStringContainsString($message, $e->getMessage(), $why);
        }
    }
}
