<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019104040 extends Migration
{
    public function getDescription(): string
    {
        return 'Accounts asked for wait for their emailed link';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}pending_accounts (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, email VARCHAR(200) NOT NULL, first_name VARCHAR(200) NOT NULL, last_name VARCHAR(200) NOT NULL, order_ids JSON NOT NULL, created_at DATETIME NOT NULL, INDEX pending_account_email (email), UNIQUE INDEX pending_account_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}pending_accounts');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
