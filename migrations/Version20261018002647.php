<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018002647 extends Migration
{
    public function getDescription(): string
    {
        return 'Shipping rates can ship to some postcodes only';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // MySQL refuses a literal default on a JSON column: add it empty, fill it, then require it.
        $this->addSql('ALTER TABLE {prefix}shipping_rates ADD postcodes JSON DEFAULT NULL');
        $this->addSql('UPDATE {prefix}shipping_rates SET postcodes = \'[]\'');
        $this->addSql('ALTER TABLE {prefix}shipping_rates MODIFY postcodes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}shipping_rates DROP postcodes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
