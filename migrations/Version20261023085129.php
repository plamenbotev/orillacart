<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Each change to an order's price keeps its key, shared by its shares over the lines.
 */
final class Version20261023085129 extends Migration
{
    public function getDescription(): string
    {
        return 'Order adjustments keep the change they are part of';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_adjustments ADD change_key VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_adjustments DROP change_key');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
