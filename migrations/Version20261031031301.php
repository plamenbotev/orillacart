<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Customers' turns at first orders: of two first orders placed at once, with different codes for first orders, the
 * second waits for the first's uses and is refused its code, while other customers' orders do not wait.
 */
final class Version20261031031301 extends Migration
{
    public function getDescription(): string
    {
        return 'Customers take turns at first orders: coupon_first_order_turns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}coupon_first_order_turns (who VARCHAR(64) NOT NULL, PRIMARY KEY (who)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}coupon_first_order_turns');
    }
}
