<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Tax groups and products keep what tax services call their goods.
 */
final class Version20261023090222 extends Migration
{
    public function getDescription(): string
    {
        return 'Tax codes for tax services on tax groups and products';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}products ADD tax_code VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}tax_groups ADD tax_code VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}products DROP tax_code');
        $this->addSql('ALTER TABLE {prefix}tax_groups DROP tax_code');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
