<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Reported orders keep the state they went to, for economic nexus; a rebuild of the reports fills it in.
 */
final class Version20261023090902 extends Migration
{
    public function getDescription(): string
    {
        return 'Reported orders keep their state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_orders ADD region VARCHAR(3) DEFAULT NULL');
        $this->addSql('CREATE INDEX report_order_region ON {prefix}report_orders (country, region, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX report_order_region ON {prefix}report_orders');
        $this->addSql('ALTER TABLE {prefix}report_orders DROP region');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
