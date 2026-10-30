<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * When a product's details were last saved, in microseconds: a save from what was loaded before another save is
 * refused rather than overwriting it.
 */
final class Version20261030170000 extends Migration
{
    public function getDescription(): string
    {
        return 'Products keep when their details were last saved: products.updated_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}products ADD updated_at BIGINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}products DROP updated_at');
    }
}
