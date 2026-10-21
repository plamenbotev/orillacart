<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The categories a shop filter shows in, and its place among each category's filters.
 */
final class Version20261021194615 extends Migration
{
    public function getDescription(): string
    {
        return 'Shop filters by category';
    }

    public function up(Schema $schema): void
    {
        // MySQL refuses a literal default on a JSON column: add them empty, fill them, then require them.
        $this->addSql('ALTER TABLE {prefix}search_filters ADD category_ids JSON DEFAULT NULL, ADD category_positions JSON DEFAULT NULL');
        $this->addSql('UPDATE {prefix}search_filters SET category_ids = \'[]\', category_positions = \'[]\'');
        $this->addSql('ALTER TABLE {prefix}search_filters MODIFY category_ids JSON NOT NULL, MODIFY category_positions JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}search_filters DROP category_ids, DROP category_positions');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
