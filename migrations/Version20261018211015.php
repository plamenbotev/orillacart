<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Invoices and credit notes, and the counters their gapless numbers come from.
 */
final class Version20261018211015 extends Migration
{
    public function getDescription(): string
    {
        return 'Invoices, credit notes and their number counters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}invoice_counters (next INT NOT NULL, series VARCHAR(40) NOT NULL, PRIMARY KEY (series)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}invoices (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(19, 4) NOT NULL, order_id INT NOT NULL, kind VARCHAR(20) NOT NULL, number VARCHAR(40) NOT NULL, currency VARCHAR(3) NOT NULL, seller JSON NOT NULL, issued_at DATETIME NOT NULL, credited_lines JSON NOT NULL, INDEX invoice_order (order_id), UNIQUE INDEX invoice_number (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}invoice_counters');
        $this->addSql('DROP TABLE {prefix}invoices');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
