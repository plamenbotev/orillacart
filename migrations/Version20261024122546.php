<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Reported orders keep the exchange rate they were priced at, so the reports count other currencies at it
 * (rebuild the reports for orders reported before).
 */
final class Version20261024122546 extends Migration
{
    public function getDescription(): string
    {
        return 'Reported orders keep their exchange rate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_orders ADD exchange_rate NUMERIC(18, 8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_orders DROP exchange_rate');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
