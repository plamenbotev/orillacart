<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Search\Infrastructure\Doctrine\FullTextQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FullTextQueryTest extends TestCase
{
    /**
     * @param list<string> $shortWords
     */
    #[DataProvider('queries')]
    public function testLongWordsAreRequiredPrefixesShortOnesGoToTheTitleAndStopwordsAreDropped(string $text, ?string $boolean, array $shortWords): void
    {
        $query = FullTextQuery::parse($text);

        self::assertSame([$boolean, $shortWords], [$query->boolean, $query->shortWords]);
    }

    /**
     * @return iterable<string, array{string, ?string, list<string>}>
     */
    public static function queries(): iterable
    {
        yield 'words' => ['Brass Lamp', '+brass* +lamp*', []];
        yield 'short words' => ['TV stand', '+stand*', ['tv']];
        yield 'stopwords' => ['the lamp for the desk', '+lamp* +desk*', []];
        yield 'operators are only separators' => ['+lamp -"desk" (x)*', '+lamp* +desk*', ['x']];
        yield 'letters of any script' => ['Лампа за бюро', '+лампа* +бюро*', ['за']];
        yield 'repeated words once' => ['lamp lamp', '+lamp*', []];
        yield 'nothing but stopwords' => ['the', null, []];
    }
}
