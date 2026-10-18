<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchFilterTest extends TestCase
{
    public function testAttributeNamesAreKeptOnceWhateverTheirCaseOrSpaces(): void
    {
        $filter = new SearchFilter(' Size ', 'size', ['Size', 'size ', 'Размер', '  ', 'Shoe  size']);

        self::assertSame(['Size', ['Size', 'Размер', 'Shoe size']], [$filter->label(), $filter->attributes()]);
    }

    /**
     * @param list<string> $attributes
     */
    #[DataProvider('wrong')]
    public function testAFilterNeedsALabelAKeyForAddressesAndAttributes(string $label, string $key, array $attributes, string $code): void
    {
        try {
            new SearchFilter($label, $key, $attributes);
            self::fail($code);
        } catch (SearchRuleViolation $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string}>
     */
    public static function wrong(): iterable
    {
        yield 'no label' => [' ', 'size', ['Size'], 'orillacart_invalid_filter_label'];
        yield 'a key with spaces' => ['Size', 'shoe size', ['Size'], 'orillacart_invalid_filter_key'];
        yield 'a key in capitals' => ['Size', 'Size', ['Size'], 'orillacart_invalid_filter_key'];
        yield 'no attributes' => ['Size', 'size', [' '], 'orillacart_invalid_filter_attributes'];
    }
}
