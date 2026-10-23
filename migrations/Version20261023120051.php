<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Tax rates for some cities only, and rates that leave shipping untaxed.
 */
final class Version20261023120051 extends Migration
{
    public function getDescription(): string
    {
        return 'Tax rates keep their cities and whether they apply to shipping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}tax_rates ADD cities JSON NOT NULL, ADD shipping TINYINT DEFAULT 1 NOT NULL');
        // The rates there are hold in every city.
        $this->addSql("UPDATE {prefix}tax_rates SET cities = '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}tax_rates DROP cities, DROP shipping');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
