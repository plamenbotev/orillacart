<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Search;

use OrillaCart\Search\Infrastructure\OpenSearch\OpenSearchLanguages;
use PHPUnit\Framework\TestCase;

final class OpenSearchLanguagesTest extends TestCase
{
    public function testALocaleHasItsLanguagesAnalyzerAndALocaleOfItsOwnComesFirst(): void
    {
        self::assertSame(
            ['german', 'german', 'brazilian', 'portuguese', 'cjk', 'english', null, null],
            array_map(OpenSearchLanguages::analyzer(...), ['de_DE', 'de_CH_informal', 'pt_BR', 'pt_PT', 'ja', 'en_GB', 'xx_YY', null]),
        );
        self::assertCount(35, OpenSearchLanguages::all(), 'each once');
    }
}
