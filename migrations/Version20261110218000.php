<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * An account asked for keeps who of the shop invited the customer, and the groups the account joins once made:
 * on a network the shop invites customers rather than making their accounts.
 */
final class Version20261110218000 extends Migration
{
    public function getDescription(): string
    {
        return 'Invitations to make an account';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('pending_accounts', 'group_ids')) {
            $this->addSql('ALTER TABLE {prefix}pending_accounts ADD group_ids JSON DEFAULT NULL');
        }
        if (!$this->hasColumn('pending_accounts', 'invited_by')) {
            $this->addSql('ALTER TABLE {prefix}pending_accounts ADD invited_by INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}pending_accounts DROP group_ids, DROP invited_by');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
