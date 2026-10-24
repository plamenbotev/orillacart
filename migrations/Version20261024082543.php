<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * An invoice keeps whom it was issued to.
 */
final class Version20261024082543 extends Migration
{
    public function getDescription(): string
    {
        return 'Invoice buyers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}invoices ADD buyer JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}invoices DROP buyer');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
