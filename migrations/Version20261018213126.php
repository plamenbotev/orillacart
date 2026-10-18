<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders remember whether their amounts include tax.
 */
final class Version20261018213126 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders remember whether their amounts include tax';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD prices_include_tax TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP prices_include_tax');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
