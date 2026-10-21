<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Tax rates for some postcodes only, and compound rates.
 */
final class Version20261021112908 extends Migration
{
    public function getDescription(): string
    {
        return 'Tax rates by postcode, and compound tax rates';
    }

    public function up(Schema $schema): void
    {
        // MySQL refuses a literal default on a JSON column: add it empty, fill it, then require it.
        $this->addSql('ALTER TABLE {prefix}tax_rates ADD postcodes JSON DEFAULT NULL, ADD compound TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE {prefix}tax_rates SET postcodes = \'[]\'');
        $this->addSql('ALTER TABLE {prefix}tax_rates MODIFY postcodes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}tax_rates DROP postcodes, DROP compound');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
