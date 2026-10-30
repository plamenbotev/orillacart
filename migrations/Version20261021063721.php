<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Webhooks, and the tries at delivering to them.
 */
final class Version20261021063721 extends Migration
{
    public function getDescription(): string
    {
        return 'Webhooks';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('webhook_deliveries')) {
            $this->addSql('CREATE TABLE {prefix}webhook_deliveries (id INT AUTO_INCREMENT NOT NULL, webhook_id INT NOT NULL, event VARCHAR(36) NOT NULL, topic VARCHAR(100) NOT NULL, attempt INT NOT NULL, status INT DEFAULT NULL, error VARCHAR(255) NOT NULL, milliseconds INT NOT NULL, attempted_at DATETIME NOT NULL, INDEX webhook_deliveries_of (webhook_id, attempted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('webhooks')) {
            $this->addSql('CREATE TABLE {prefix}webhooks (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(500) NOT NULL, topics JSON NOT NULL, active TINYINT NOT NULL, secret VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}webhook_deliveries');
        $this->addSql('DROP TABLE {prefix}webhooks');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
