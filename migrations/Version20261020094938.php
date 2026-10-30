<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020094938 extends Migration
{
    public function getDescription(): string
    {
        return 'The shop\'s own pickup: places where customers collect their orders';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('carriers', 'pickup_locations')) {
            $this->addSql('ALTER TABLE {prefix}carriers ADD pickup_locations JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}carriers SET pickup_locations = \'[]\' WHERE pickup_locations IS NULL');
        $this->addSql('ALTER TABLE {prefix}carriers MODIFY pickup_locations JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}carriers DROP pickup_locations');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
