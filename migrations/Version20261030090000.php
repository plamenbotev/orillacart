<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The refunds reported to tax services, so a refund job run again reports nothing twice.
 */
final class Version20261030090000 extends Migration
{
    public function getDescription(): string
    {
        return 'The refunds reported to tax services';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}tax_refund_reports (reference VARCHAR(100) NOT NULL, order_id INT NOT NULL, reported_at DATETIME NOT NULL, PRIMARY KEY (reference)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}tax_refund_reports');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
