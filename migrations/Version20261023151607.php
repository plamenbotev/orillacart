<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Price rules say whether codes and automatic discounts still come off their price, and whether they combine with
 * the other rules instead of competing with them.
 */
final class Version20261023151607 extends Migration
{
    public function getDescription(): string
    {
        return 'Price rules with coupons and combining';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}price_rules ADD with_coupons TINYINT DEFAULT 1 NOT NULL, ADD combines TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}price_rules DROP with_coupons, DROP combines');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
