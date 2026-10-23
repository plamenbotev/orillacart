<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders keep the business customer's own reference for them, for the invoice and the e-invoice (BT-10).
 */
final class Version20261023123435 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders keep the buyer\'s reference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD buyer_reference VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP buyer_reference');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
