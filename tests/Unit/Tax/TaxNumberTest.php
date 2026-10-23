<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Tax\Domain\TaxNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function numbers(): iterable
    {
        yield 'UK, as typed' => ['GB', 'GB 980 7806 84', 'GB980780684'];
        yield 'UK, without its prefix' => ['GB', '980780684', 'GB980780684'];
        yield 'UK, a branch' => ['GB', 'GB980780684001', 'GB980780684001'];
        yield 'UK, a government department' => ['GB', 'GBGD001', 'GBGD001'];
        yield 'UK, a wrong check digit' => ['GB', 'GB980780685', null];
        yield 'Switzerland' => ['CH', 'CHE-116.281.710 MWST', 'CHE116281710MWST'];
        yield 'Switzerland, a wrong check digit' => ['CH', 'CHE-116.281.711', null];
        yield 'Norway' => ['NO', '974 760 673 MVA', 'NO974760673MVA'];
        yield 'Norway, a wrong check digit' => ['NO', '974760674', null];
        yield 'Australia' => ['AU', '51 824 753 556', '51824753556'];
        yield 'Australia, a wrong check digit' => ['AU', '51 824 753 557', null];
        yield 'New Zealand' => ['NZ', '49-091-850', '49091850'];
        yield 'New Zealand, nine digits' => ['NZ', '136-410-132', '136410132'];
        yield 'New Zealand, a wrong check digit' => ['NZ', '136-410-133', null];
        yield 'Canada' => ['CA', '123456782', '123456782'];
        yield 'Canada, with its GST/HST account' => ['CA', '123456782 RT 0001', '123456782RT0001'];
        yield 'Canada, a wrong check digit' => ['CA', '123456789', null];
        yield 'elsewhere, kept as typed' => ['JP', 't-1234567890123', 'T1234567890123'];
        yield 'nothing' => ['GB', ' - ', null];
    }

    #[DataProvider('numbers')]
    public function testANumberIsCheckedByItsCountrysRules(string $country, string $typed, ?string $expected): void
    {
        self::assertSame($expected, TaxNumber::check($country, $typed));
    }
}
