<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A free-shipping coupon says whether every way to ship is free, or the cheapest.
 */
final class Version20261024085512 extends Migration
{
    public function getDescription(): string
    {
        return 'Free shipping on every rate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons ADD free_shipping_every_rate TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons DROP free_shipping_every_rate');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
