<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018000248 extends Migration
{
    public function getDescription(): string
    {
        return 'Stockrooms speak of delivery time in days or weeks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}stockrooms ADD delivery_unit VARCHAR(10) DEFAULT \'days\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}stockrooms DROP delivery_unit');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
