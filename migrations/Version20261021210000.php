<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A category chooses its filters, in its order, instead of each filter naming its categories.
 */
final class Version20261021210000 extends Migration
{
    public function getDescription(): string
    {
        return 'Categories choose their filters';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('search_category_filters')) {
            $this->addSql('CREATE TABLE {prefix}search_category_filters (category_id INT NOT NULL, filter_ids JSON NOT NULL, PRIMARY KEY (category_id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if ($this->hasColumn('search_filters', 'category_ids')) {
            $this->addSql('ALTER TABLE {prefix}search_filters DROP category_ids, DROP category_positions');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}search_category_filters');
        $this->addSql('ALTER TABLE {prefix}search_filters ADD category_ids JSON DEFAULT NULL, ADD category_positions JSON DEFAULT NULL');
        $this->addSql('UPDATE {prefix}search_filters SET category_ids = \'[]\', category_positions = \'[]\'');
        $this->addSql('ALTER TABLE {prefix}search_filters MODIFY category_ids JSON NOT NULL, MODIFY category_positions JSON NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
