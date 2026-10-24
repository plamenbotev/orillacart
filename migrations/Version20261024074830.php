<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The problems the shop logged, for the shop manager to read on the Status screen.
 */
final class Version20261024074830 extends Migration
{
    public function getDescription(): string
    {
        return 'Shop problems';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}problems (id INT AUTO_INCREMENT NOT NULL, fingerprint CHAR(40) NOT NULL, level VARCHAR(10) NOT NULL, message LONGTEXT NOT NULL, count INT NOT NULL, first_at DATETIME NOT NULL, last_at DATETIME NOT NULL, INDEX problems_last (last_at), UNIQUE INDEX problems_fingerprint (fingerprint), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}problems');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
