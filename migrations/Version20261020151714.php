<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The shop's notes about its customers.
 */
final class Version20261020151714 extends Migration
{
    public function getDescription(): string
    {
        return 'Customer notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}customer_notes (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, customer_id INT NOT NULL, user_id INT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX customer_notes_of (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}customer_notes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
