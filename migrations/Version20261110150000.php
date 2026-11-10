<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * An enabled extension keeps the files it was enabled with and runs those until enabled again (security review,
 * PLAN 389). One enabled before this keeps running its files as they are until a manager enables it again.
 */
final class Version20261110150000 extends Migration
{
    public function getDescription(): string
    {
        return 'The files an extension was enabled with';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('packages', 'enabled_files')) {
            $this->addSql('ALTER TABLE {prefix}packages ADD enabled_files JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}packages DROP enabled_files');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
