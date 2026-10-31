<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A charge names the products it is for, so their share of it goes back with them.
 */
final class Version20261101140000 extends Migration
{
    public function getDescription(): string
    {
        return 'Charges for products';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('cart_lines', 'charge_for')) {
            $this->addSql('ALTER TABLE {prefix}cart_lines ADD charge_for JSON DEFAULT NULL');
        }
        if (!$this->hasColumn('order_lines', 'charge_for')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD charge_for JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_lines DROP charge_for');
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP charge_for');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
