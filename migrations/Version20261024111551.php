<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A carrier may keep goods of a class to the rates made for it.
 */
final class Version20261024111551 extends Migration
{
    public function getDescription(): string
    {
        return 'Carriers keep classes to their rates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}carriers ADD classes_kept_to_their_rates TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}carriers DROP classes_kept_to_their_rates');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
