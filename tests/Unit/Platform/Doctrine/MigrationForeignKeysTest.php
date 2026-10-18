<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use OrillaCart\Platform\Doctrine\Migration;
use PHPUnit\Framework\TestCase;

final class MigrationForeignKeysTest extends TestCase
{
    private const string SQL = 'ALTER TABLE wp_2_orillacart_lines ADD CONSTRAINT FK_A6CD9014584665A FOREIGN KEY (cart_id) REFERENCES wp_2_orillacart_carts (id); ALTER TABLE x DROP FOREIGN KEY FK_37761615F026BB7C';

    public function testTheSiteMigrationsAreMadeOnKeepsTheGeneratedNames(): void
    {
        self::assertSame(self::SQL, Migration::foreignKeys(self::SQL, Migration::GENERATED_WITH));
    }

    public function testEveryOtherSiteGetsNamesOfItsOwnTheSameInEveryStatement(): void
    {
        $second = Migration::foreignKeys(self::SQL, 'wp_2_orillacart_');
        $third = Migration::foreignKeys(self::SQL, 'wp_3_orillacart_');

        preg_match_all('/FK_[0-9A-F]+/', $second, $names);
        self::assertCount(2, array_unique($names[0]));
        self::assertNotContains('FK_A6CD9014584665A', $names[0]);
        self::assertNotSame($second, $third);
        self::assertSame($second, Migration::foreignKeys(self::SQL, 'wp_2_orillacart_'));
        self::assertStringContainsString('REFERENCES wp_2_orillacart_carts (id)', $second, 'only the names change');
    }
}
