<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261026074157 extends Migration
{
    public function getDescription(): string
    {
        return 'OAuth for AI assistants: clients that registered, tokens, and the client a key was connected with';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}oauth_clients (id VARCHAR(64) NOT NULL, name VARCHAR(200) NOT NULL, redirect_uris JSON NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}oauth_tokens (id INT AUTO_INCREMENT NOT NULL, client_id VARCHAR(255) NOT NULL, access_hash VARCHAR(64) NOT NULL, access_expires_at DATETIME NOT NULL, refresh_hash VARCHAR(64) NOT NULL, refresh_expires_at DATETIME NOT NULL, api_key_id INT NOT NULL, UNIQUE INDEX oauth_access_hash (access_hash), UNIQUE INDEX oauth_refresh_hash (refresh_hash), INDEX IDX_127F438E8BE312B3 (api_key_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}oauth_tokens ADD CONSTRAINT FK_127F438E8BE312B3 FOREIGN KEY (api_key_id) REFERENCES {prefix}api_keys (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE {prefix}api_keys ADD client VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}oauth_tokens DROP FOREIGN KEY FK_127F438E8BE312B3');
        $this->addSql('DROP TABLE {prefix}oauth_clients');
        $this->addSql('DROP TABLE {prefix}oauth_tokens');
        $this->addSql('ALTER TABLE {prefix}api_keys DROP client');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
