<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Order lines remember the stock they gave back when their order closed unpaid, to take it again if the money
 * comes late.
 */
final class Version20261018175942 extends Migration
{
    public function getDescription(): string
    {
        return 'Order lines remember the stock they gave back';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_lines ADD stock_released JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_lines DROP stock_released');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
