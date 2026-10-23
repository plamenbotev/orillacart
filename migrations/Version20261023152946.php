<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A coupon can say it comes off prices whose price rule keeps coupons out (a staff or apology code).
 */
final class Version20261023152946 extends Migration
{
    public function getDescription(): string
    {
        return 'Coupons that override price rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons ADD overrides_price_rules TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons DROP overrides_price_rules');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
