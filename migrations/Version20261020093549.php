<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020093549 extends Migration
{
    public function getDescription(): string
    {
        return 'Shipments of some of an order\'s items, in parcels with their own numbers, and shipments the shop sent itself';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('consignments', 'items')) {
            $this->addSql('ALTER TABLE {prefix}consignments ADD items JSON DEFAULT NULL, ADD parcels JSON DEFAULT NULL, CHANGE carrier_id carrier_id INT DEFAULT NULL, CHANGE method method VARCHAR(40) DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}consignments SET items = \'[]\', parcels = \'[]\' WHERE items IS NULL');
        $this->addSql('ALTER TABLE {prefix}consignments MODIFY items JSON NOT NULL, MODIFY parcels JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}consignments DROP items, DROP parcels, CHANGE carrier_id carrier_id INT NOT NULL, CHANGE method method VARCHAR(40) NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
