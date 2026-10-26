<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261026070201 extends Migration
{
    public function getDescription(): string
    {
        return 'API keys keep what the owner chose for the AI assistant that uses them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}api_keys ADD assistant JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}api_keys DROP assistant');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
