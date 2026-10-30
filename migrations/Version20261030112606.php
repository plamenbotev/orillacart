<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261030112606 extends Migration
{
    public function getDescription(): string
    {
        return 'A provider names each refund once: its reference is unique per gateway, so a notification sent twice records one refund; the duplicates recorded before keep it on the oldest row only';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off between the statements goes on from where it stopped when it runs again: the
        // index keeps its name, so whether it is unique yet tells them apart, and the updates find nothing to do
        // a second time.
        if (!$this->hasIndex('refunds', 'refund_reference', unique: true)) {
            // A gateway that named no reference gave an empty one: none, as the shop stores it now.
            $this->addSql("UPDATE {prefix}refunds SET provider_reference = NULL WHERE provider_reference = ''");
            // The rows a notification sent twice recorded twice: the oldest keeps the reference, the later ones
            // lose it, so the index can be made where they are.
            $this->addSql('UPDATE {prefix}refunds r JOIN (SELECT gateway, provider_reference, MIN(id) AS kept FROM {prefix}refunds WHERE provider_reference IS NOT NULL GROUP BY gateway, provider_reference HAVING COUNT(*) > 1) d ON r.gateway = d.gateway AND r.provider_reference = d.provider_reference SET r.provider_reference = NULL WHERE r.id <> d.kept');
            if ($this->hasIndex('refunds', 'refund_reference')) {
                $this->addSql('DROP INDEX refund_reference ON {prefix}refunds');
            }
            $this->addSql('CREATE UNIQUE INDEX refund_reference ON {prefix}refunds (gateway, provider_reference)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX refund_reference ON {prefix}refunds');
        $this->addSql('CREATE INDEX refund_reference ON {prefix}refunds (gateway, provider_reference)');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
