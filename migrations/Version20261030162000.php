<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Refunds in the reports keep the money given back with its tax and the items' share of it before tax, so total
 * sales lose the one and net sales the other; a cart left at checkout counts recovered once its order is paid.
 * Every order is counted again, as the reports now count them (refunds, the counting day, prices with tax).
 */
final class Version20261030162000 extends Migration
{
    public function getDescription(): string
    {
        return 'Refunds keep the items\' share before tax, carts recover when their order is paid, and the reports count every order again';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off between the statements goes on from where it stopped when it runs again; the
        // data statements give the same result run twice.
        if (!$this->hasColumn('report_refunds', 'items')) {
            $this->addSql('ALTER TABLE {prefix}report_refunds ADD items NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        }
        if (!$this->hasIndex('abandoned_carts', 'abandoned_order')) {
            $this->addSql('CREATE INDEX abandoned_order ON {prefix}abandoned_carts (order_id)');
        }
        // Recovered when the order was paid, not when it was placed; one never paid recovered nothing.
        $this->addSql('UPDATE {prefix}abandoned_carts a LEFT JOIN {prefix}orders o ON o.id = a.order_id SET a.recovered_at = o.paid_at WHERE a.order_id IS NOT NULL');
        $this->addSql(
            "INSERT INTO {prefix}jobs (queue, type, unique_key, payload, available_at, created_at, version, attempts)
             VALUES ('reports', 'reports.rebuild', 'all', '{\"after\":0}', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 0)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), available_at = LEAST(available_at, VALUES(available_at)), attempts = 0, last_error = NULL",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX abandoned_order ON {prefix}abandoned_carts');
        $this->addSql('ALTER TABLE {prefix}report_refunds DROP items');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
