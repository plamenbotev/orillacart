<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019153839 extends Migration
{
    public function getDescription(): string
    {
        return 'Products and variations may owe so many on backorder at most';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('products', 'backorder_limit')) {
            $this->addSql('ALTER TABLE {prefix}products ADD backorder_limit INT DEFAULT NULL');
        }
        if (!$this->hasColumn('variations', 'backorder_limit')) {
            $this->addSql('ALTER TABLE {prefix}variations ADD backorder_limit INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}products DROP backorder_limit');
        $this->addSql('ALTER TABLE {prefix}variations DROP backorder_limit');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
