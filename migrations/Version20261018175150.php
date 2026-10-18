<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A cart remembers the order being placed from it while its payment starts, so a second submit places none.
 */
final class Version20261018175150 extends Migration
{
    public function getDescription(): string
    {
        return 'Carts remember the order being placed from them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}carts ADD placing_order_id INT DEFAULT NULL, ADD placing_since DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}carts DROP placing_order_id, DROP placing_since');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
