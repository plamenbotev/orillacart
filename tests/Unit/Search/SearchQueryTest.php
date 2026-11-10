<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Search\Application\Dto\SearchQuery;
use PHPUnit\Framework\TestCase;

/** A search takes so many words and brands, each another condition, and no more. */
final class SearchQueryTest extends TestCase
{
    public function testASearchKeepsItsFirstWordsAndBrands(): void
    {
        $query = new SearchQuery(text: ' one two  three four five six seven eight nine ', brandIds: range(1, 30))->bounded();

        self::assertSame('one two three four five six seven eight', $query->text);
        self::assertSame(range(1, 20), $query->brandIds);
        self::assertSame(100, mb_strlen((string) new SearchQuery(text: str_repeat('ж', 300))->bounded()->text));
    }

    public function testASearchWithinTheBoundsStaysAsItIs(): void
    {
        $query = new SearchQuery(text: 'brass lamp', brandIds: [3]);

        self::assertSame($query, $query->bounded());
        self::assertNull(new SearchQuery()->bounded()->text);
    }
}
