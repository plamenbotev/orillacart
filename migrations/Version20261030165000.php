<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A products import keeps the line of the file its next row starts on, so a refused row is named by its line,
 * blank lines counted.
 */
final class Version20261030165000 extends Migration
{
    public function getDescription(): string
    {
        return 'Products imports keep the line their next row starts on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}product_imports ADD line INT DEFAULT 2 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}product_imports DROP line');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
