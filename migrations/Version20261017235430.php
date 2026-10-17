<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017235430 extends Migration
{
    public function getDescription(): string
    {
        return 'Products can hide their price, or follow the shop\'s setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}products ADD price_visibility VARCHAR(10) DEFAULT \'shop\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}products DROP price_visibility');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
