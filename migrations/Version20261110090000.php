<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Test orders (design 62): an order placed to try the shop out, never emailed and left out of the reports.
 */
final class Version20261110090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Test orders';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('orders', 'test')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD test TINYINT(1) DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP test');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
