<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017133600 extends Migration
{
    private const int ROWS_PER_INSERT = 100;

    public function getDescription(): string
    {
        return 'ISO 3166 countries and the states addresses use';
    }

    public function up(Schema $schema): void
    {
        /** @var array{countries: list<array{string, string, string, bool}>, states: list<array{string, string, string}>} $data */
        $data = require __DIR__ . '/data/iso-3166.php';

        foreach (array_chunk($data['countries'], self::ROWS_PER_INSERT) as $rows) {
            $this->insert('{prefix}countries (code, alpha3, name, in_eu)', array_map(
                static fn(array $row): array => [$row[0], $row[1], $row[2], (int) $row[3]],
                $rows,
            ));
        }
        foreach (array_chunk($data['states'], self::ROWS_PER_INSERT) as $rows) {
            $this->insert('{prefix}states (country_code, code, name)', $rows);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM {prefix}states');
        $this->addSql('DELETE FROM {prefix}countries');
    }

    /**
     * @param non-empty-list<list<string|int>> $rows
     */
    private function insert(string $into, array $rows): void
    {
        $row = '(' . implode(', ', array_fill(0, \count($rows[0]), '?')) . ')';
        $this->addSql('INSERT INTO ' . $into . ' VALUES ' . implode(', ', array_fill(0, \count($rows), $row)), array_merge(...$rows));
    }
}
