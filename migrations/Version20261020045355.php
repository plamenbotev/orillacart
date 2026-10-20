<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020045355 extends Migration
{
    public function getDescription(): string
    {
        return 'Products imports: the file, how far it is read, and what came of its rows';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}product_imports (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(10) NOT NULL, position BIGINT NOT NULL, done INT NOT NULL, created INT NOT NULL, updated INT NOT NULL, failed INT NOT NULL, errors JSON NOT NULL, finished_at DATETIME DEFAULT NULL, file_key VARCHAR(100) NOT NULL, filename VARCHAR(255) NOT NULL, columns JSON NOT NULL, row_count INT NOT NULL, user_id INT DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}product_imports');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
