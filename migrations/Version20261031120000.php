<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * What the product page tells about delivery and the product: how many working days a carrier takes, for the
 * delivery estimate, and whether an attribute is listed in the product's specifications.
 */
final class Version20261031120000 extends Migration
{
    public function getDescription(): string
    {
        return 'Carriers\' delivery days; attributes in the specifications';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('carriers', 'delivery_min_days')) {
            $this->addSql('ALTER TABLE {prefix}carriers ADD delivery_min_days INT DEFAULT NULL, ADD delivery_max_days INT DEFAULT NULL');
        }
        if (!$this->hasColumn('attributes', 'in_specifications')) {
            $this->addSql('ALTER TABLE {prefix}attributes ADD in_specifications TINYINT DEFAULT 1 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}carriers DROP delivery_min_days, DROP delivery_max_days');
        $this->addSql('ALTER TABLE {prefix}attributes DROP in_specifications');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
