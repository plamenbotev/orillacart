<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020091004 extends Migration
{
    public function getDescription(): string
    {
        return 'Shipping rates by how many items the order has';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}shipping_rates ADD items_min NUMERIC(18, 4) NOT NULL, ADD items_max NUMERIC(18, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}shipping_rates DROP items_min, DROP items_max');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
