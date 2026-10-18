<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018003024 extends Migration
{
    public function getDescription(): string
    {
        return 'Shipping rates can leave out regions and postcodes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // MySQL refuses a literal default on a JSON column: add them empty, fill them, then require them.
        $this->addSql('ALTER TABLE {prefix}shipping_rates ADD excluded_regions JSON DEFAULT NULL, ADD excluded_postcodes JSON DEFAULT NULL');
        $this->addSql('UPDATE {prefix}shipping_rates SET excluded_regions = \'[]\', excluded_postcodes = \'[]\'');
        $this->addSql('ALTER TABLE {prefix}shipping_rates MODIFY excluded_regions JSON NOT NULL, MODIFY excluded_postcodes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}shipping_rates DROP excluded_regions, DROP excluded_postcodes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
