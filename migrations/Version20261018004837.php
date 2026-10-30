<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018004837 extends Migration
{
    public function getDescription(): string
    {
        return 'Backorders: products and variations sell on when out of stock; order lines record what is owed';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('order_lines', 'backordered')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD backordered INT DEFAULT 0 NOT NULL, ADD backorder_shown TINYINT DEFAULT 0 NOT NULL');
        }
        if (!$this->hasColumn('products', 'backorders')) {
            $this->addSql('ALTER TABLE {prefix}products ADD backorders VARCHAR(10) DEFAULT \'no\' NOT NULL');
        }
        if (!$this->hasColumn('variations', 'backorders')) {
            $this->addSql('ALTER TABLE {prefix}variations ADD backorders VARCHAR(10) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}order_lines DROP backordered, DROP backorder_shown');
        $this->addSql('ALTER TABLE {prefix}products DROP backorders');
        $this->addSql('ALTER TABLE {prefix}variations DROP backorders');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
