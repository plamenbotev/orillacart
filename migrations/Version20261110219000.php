<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A guest's order that waits for a transfer keeps a key of where it was placed from (PLAN 514), so the orders
 * one place holds unpaid at once are counted as a customer's are: against the shop's setting, and only while
 * they wait.
 */
final class Version20261110219000 extends Migration
{
    public function getDescription(): string
    {
        return 'Where a guest placed an order that waits unpaid';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('orders', 'caller_key')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD caller_key VARCHAR(32) DEFAULT NULL');
        }
        if (!$this->hasIndex('orders', 'order_caller_key')) {
            $this->addSql('CREATE INDEX order_caller_key ON {prefix}orders (caller_key)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX order_caller_key ON {prefix}orders');
        $this->addSql('ALTER TABLE {prefix}orders DROP caller_key');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
