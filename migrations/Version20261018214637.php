<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Products' prices over time, for the lowest price of the 30 days before a sale (EU Omnibus).
 */
final class Version20261018214637 extends Migration
{
    public function getDescription(): string
    {
        return 'Price history for the lowest price before a sale';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}price_history (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(19, 4) NOT NULL, item VARCHAR(40) NOT NULL, recorded_at DATETIME NOT NULL, INDEX price_item (item, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}price_history');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
