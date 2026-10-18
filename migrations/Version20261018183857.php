<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders can need the shop's attention; payments remember when the provider was last asked about them; a refund
 * the provider finishes later has its own status instead of a flag.
 */
final class Version20261018183857 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders needing attention, payments checked at the provider, accepted refunds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD attention LONGTEXT DEFAULT NULL, ADD attention_since DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}payments ADD checked_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX payment_open ON {prefix}payments (status, checked_at)');
        $this->addSql('ALTER TABLE {prefix}refunds DROP provider_pending');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP attention, DROP attention_since');
        $this->addSql('DROP INDEX payment_open ON {prefix}payments');
        $this->addSql('ALTER TABLE {prefix}payments DROP checked_at');
        $this->addSql('ALTER TABLE {prefix}refunds ADD provider_pending TINYINT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
