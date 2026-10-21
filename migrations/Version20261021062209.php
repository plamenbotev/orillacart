<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The change log: who of the shop changed what, through the shop's API.
 */
final class Version20261021062209 extends Migration
{
    public function getDescription(): string
    {
        return 'Change log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}change_log (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, user_name VARCHAR(100) NOT NULL, area VARCHAR(64) NOT NULL, route VARCHAR(200) NOT NULL, path VARCHAR(255) NOT NULL, sent_values JSON NOT NULL, made_at DATETIME NOT NULL, INDEX change_log_when (made_at), INDEX change_log_area (area, made_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}change_log');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
