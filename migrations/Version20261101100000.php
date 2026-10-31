<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The reports count the items discounts give apart from the items sold.
 */
final class Version20261101100000 extends Migration
{
    public function getDescription(): string
    {
        return 'Items given counted apart in the reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_order_lines ADD given TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}report_order_lines DROP given');
    }
}
