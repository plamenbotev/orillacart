<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261030112606 extends Migration
{
    public function getDescription(): string
    {
        return 'A provider names each refund once: its reference is unique per gateway, so a notification sent twice records one refund';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off between the two statements goes on from the second when it runs again: the
        // index keeps its name, so whether it is unique yet tells the two apart.
        if (!$this->hasIndex('refunds', 'refund_reference', unique: true)) {
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
