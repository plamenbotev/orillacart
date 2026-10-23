<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261023073630 extends Migration
{
    public function getDescription(): string
    {
        return 'A tax rate\'s filing code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}tax_rates ADD code VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}tax_rates DROP code');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
