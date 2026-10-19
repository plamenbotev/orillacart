<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019124040 extends Migration
{
    public function getDescription(): string
    {
        return 'Each payment provider event is handled once';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}payment_events (gateway VARCHAR(40) NOT NULL, event_id VARCHAR(100) NOT NULL, received_at DATETIME NOT NULL, INDEX payment_event_received (received_at), PRIMARY KEY (gateway, event_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}payment_events');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
