<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Storefront;

use OrillaCart\Storefront\Infrastructure\WordPress\ActiveFilters;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Choices from \OrillaCart\Storefront\Infrastructure\WordPress\ShopListing
 * @phpstan-import-type Panel from \OrillaCart\Storefront\Infrastructure\WordPress\ShopListing
 */
final class ActiveFiltersTest extends TestCase
{
    private const string URL = 'https://shop.test/shop/?q=lamp&sort=name&brand%5B%5D=3&brand%5B%5D=5&f%5Bsize%5D%5B%5D=m&f%5Bsize%5D%5B%5D=l&f%5Bcolour%5D%5B%5D=red&price_min=10&in_stock=1&view=list';

    public function testEachChoiceIsAChipWhoseLinkDropsItAlone(): void
    {
        $chips = ActiveFilters::chips(self::choices(), self::panel(), self::URL, ['category' => 'Lamps', 'tag' => '']);

        self::assertSame(
            [['search', '', 'lamp'], ['category', '', 'Lamps'], ['filter', 'Brand', 'Acme'], ['filter', 'Brand', 'Lumen'], ['filter', 'Size', 'M'], ['filter', 'Size', 'L'], ['filter', 'Colour', 'Red'], ['price', '', ''], ['in_stock', '', '']],
            array_map(static fn(array $chip): array => [$chip['kind'], $chip['group'], $chip['label']], $chips),
        );
        $urls = array_column($chips, 'url');
        self::assertSame('https://shop.test/shop/?sort=name&brand%5B%5D=3&brand%5B%5D=5&f%5Bsize%5D%5B%5D=m&f%5Bsize%5D%5B%5D=l&f%5Bcolour%5D%5B%5D=red&price_min=10&in_stock=1&view=list', $urls[0], 'the words, keeping the sort chosen');
        self::assertSame(self::URL, $urls[1], 'the category, not in this address');
        self::assertStringContainsString('brand%5B%5D=5&f', $urls[2], 'one brand of two, written as the forms send it');
        self::assertStringNotContainsString('brand%5B%5D=3', $urls[2]);
        self::assertStringContainsString('f%5Bsize%5D%5B%5D=l&f%5Bcolour%5D%5B%5D=red', $urls[4], 'one size, the other kept');
        self::assertStringNotContainsString('colour', $urls[6], 'the only colour drops the filter');
        self::assertSame(['10', null], [$chips[7]['min'], $chips[7]['max']]);
        self::assertStringNotContainsString('price_', $urls[7]);
        self::assertStringNotContainsString('in_stock', $urls[8]);
        self::assertStringEndsWith('view=list', $urls[8], 'how the shopper sees them stays');
    }

    public function testABrandThePageIsForIsNoChoiceToDrop(): void
    {
        $chips = ActiveFilters::chips(['search' => ''] + self::choices(), self::panel(), self::URL, ['category' => '', 'tag' => ''], 3);

        self::assertSame(['Lumen', 'M', 'L', 'Red'], array_column(array_filter($chips, static fn(array $chip): bool => $chip['kind'] === 'filter'), 'label'));
    }

    public function testClearingAllKeepsTheSortAndTheView(): void
    {
        self::assertSame('https://shop.test/shop/?sort=name&view=list', ActiveFilters::clearUrl(self::URL . '&category=lamps', true));
        self::assertSame('https://shop.test/shop/?tag=gift', ActiveFilters::clearUrl('https://shop.test/shop/?q=lamp&sort=relevance&tag=gift', false), 'best match goes with the words; a tag the page fixes stays');
    }

    public function testAValueIsDroppedAsTheIndexMatchesIt(): void
    {
        self::assertSame('https://shop.test/shop/?f%5Bsize%5D%5B%5D=l#grid', ActiveFilters::without('https://shop.test/shop/?f[size][]=Size%20M&f[size][]=l#grid', ['f[size]' => 'size m']));
        self::assertSame('https://shop.test/shop/', ActiveFilters::without('https://shop.test/shop/?in_stock=1', ['in_stock' => null]));
        self::assertSame('https://shop.test/shop/', ActiveFilters::without('https://shop.test/shop/', ['q' => null]));
    }

    /**
     * @return Choices
     */
    private static function choices(): array
    {
        return ['category' => 'lamps', 'category_id' => 7, 'tag' => '', 'tag_id' => null, 'search' => 'lamp', 'brands' => [3, 5], 'options' => ['size' => ['m', 'l'], 'colour' => ['red']], 'price_min' => '10', 'price_max' => null, 'in_stock' => true, 'sort' => 'name', 'page' => 1, 'view' => 'list'];
    }

    /**
     * @return Panel
     */
    private static function panel(): array
    {
        $value = static fn(string $value, string $label, bool $checked): array => ['value' => $value, 'label' => $label, 'count' => 1, 'checked' => $checked];

        return [
            'filters' => [
                ['label' => 'Brand', 'name' => 'brand[]', 'values' => [$value('3', 'Acme', true), $value('5', 'Lumen', true), $value('9', 'Other', false)]],
                ['label' => 'Size', 'name' => 'f[size][]', 'values' => [$value('m', 'M', true), $value('l', 'L', true), $value('s', 'S', false)]],
                ['label' => 'Colour', 'name' => 'f[colour][]', 'values' => [$value('red', 'Red', true)]],
            ],
            'price' => null,
            'in_stock' => true,
            'filtered' => true,
            'clear_url' => '',
        ];
    }
}
