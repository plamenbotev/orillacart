<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261023080700 extends Migration
{
    public function getDescription(): string
    {
        return 'Reports keep the tax of each order line by jurisdiction; order taxes mark exempt sales';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('report_order_taxes')) {
            $this->addSql('CREATE TABLE {prefix}report_order_taxes (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, day DATE NOT NULL, counted TINYINT NOT NULL, currency VARCHAR(3) NOT NULL, country CHAR(2) NOT NULL, region VARCHAR(3) DEFAULT NULL, level VARCHAR(10) NOT NULL, code VARCHAR(60) NOT NULL, jurisdiction VARCHAR(100) NOT NULL, percent NUMERIC(7, 4) NOT NULL, kind VARCHAR(10) NOT NULL, taxable NUMERIC(19, 4) NOT NULL, amount NUMERIC(19, 4) NOT NULL, refunded NUMERIC(19, 4) NOT NULL, exempt TINYINT NOT NULL, INDEX report_tax_day (counted, currency, day), INDEX report_tax_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasColumn('order_taxes', 'exempt')) {
            $this->addSql('ALTER TABLE {prefix}order_taxes ADD exempt TINYINT DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}report_order_taxes');
        $this->addSql('ALTER TABLE {prefix}order_taxes DROP exempt');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
