<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders remember since when they wait for their payment, which paying later restarts.
 */
final class Version20261018210035 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders remember since when they wait for their payment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD awaiting_payment_since DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP awaiting_payment_since');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
