<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A webhook is in the name of the shop manager who saved it, counts the events it failed for good in a row, and
 * says why the shop switched it off. Webhooks saved before have no such manager: they are switched off at their
 * next event, until a manager switches them on again.
 */
final class Version20261110214000 extends Migration
{
    public function getDescription(): string
    {
        return 'Webhook owners and failed events';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('webhooks', 'owner_id')) {
            $this->addSql('ALTER TABLE {prefix}webhooks ADD owner_id INT DEFAULT NULL');
        }
        if (!$this->hasColumn('webhooks', 'failed_in_a_row')) {
            $this->addSql('ALTER TABLE {prefix}webhooks ADD failed_in_a_row INT DEFAULT 0 NOT NULL');
        }
        if (!$this->hasColumn('webhooks', 'failure_limit')) {
            $this->addSql('ALTER TABLE {prefix}webhooks ADD failure_limit INT DEFAULT 10 NOT NULL');
        }
        if (!$this->hasColumn('webhooks', 'off_reason')) {
            $this->addSql('ALTER TABLE {prefix}webhooks ADD off_reason VARCHAR(20) DEFAULT NULL');
        }
        if (!$this->hasColumn('webhooks', 'off_at')) {
            $this->addSql('ALTER TABLE {prefix}webhooks ADD off_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}webhooks DROP owner_id, DROP failed_in_a_row, DROP failure_limit, DROP off_reason, DROP off_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
