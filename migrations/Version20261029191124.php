<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029191124 extends Migration
{
    public function getDescription(): string
    {
        return 'The search index: a product\'s words in each language it is translated into, and its values\' names in them';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('search_documents', 'locale')) {
            $this->addSql('ALTER TABLE {prefix}search_documents ADD locale VARCHAR(20) DEFAULT \'\' NOT NULL, ADD original TINYINT DEFAULT 1 NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (product_id, locale)');
        }
        if (!$this->hasColumn('search_values', 'labels')) {
            $this->addSql('ALTER TABLE {prefix}search_values ADD labels JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DELETE FROM {prefix}search_documents WHERE original = 0');
        $this->addSql('ALTER TABLE {prefix}search_documents DROP locale, DROP original, DROP PRIMARY KEY, ADD PRIMARY KEY (product_id)');
        $this->addSql('ALTER TABLE {prefix}search_values DROP labels');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
