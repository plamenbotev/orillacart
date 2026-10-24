<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Webhook secrets are kept sealed, which takes more room.
 */
final class Version20261024062307 extends Migration
{
    public function getDescription(): string
    {
        return 'Sealed webhook secrets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}webhooks CHANGE secret secret VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}webhooks CHANGE secret secret VARCHAR(64) NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
