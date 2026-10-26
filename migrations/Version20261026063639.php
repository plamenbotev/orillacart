<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261026063639 extends Migration
{
    public function getDescription(): string
    {
        return 'The change log says when a change came from the AI assistant or an API key, and which key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}change_log ADD via VARCHAR(20) DEFAULT NULL, ADD key_name VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}change_log DROP via, DROP key_name');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
