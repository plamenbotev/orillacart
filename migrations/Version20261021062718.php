<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The keys integrations call the shop's API with.
 */
final class Version20261021062718 extends Migration
{
    public function getDescription(): string
    {
        return 'API keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}api_keys (id INT AUTO_INCREMENT NOT NULL, last_used_at DATETIME DEFAULT NULL, name VARCHAR(100) NOT NULL, user_id INT NOT NULL, scope VARCHAR(20) NOT NULL, prefix VARCHAR(16) NOT NULL, hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX api_key_hash (hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}api_keys');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
