<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Reported orders keep whether the reverse charge applied, so sales to consumers in other EU countries can be
 * measured against the EU's threshold. Orders reported before read as sales to consumers until the reports are
 * rebuilt.
 */
final class Version20261023120845 extends Migration
{
    public function getDescription(): string
    {
        return 'Reported orders keep whether the reverse charge applied';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_orders ADD reverse_charge TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_orders DROP reverse_charge');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
