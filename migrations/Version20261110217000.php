<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A return keeps what its refund gave back of each line's worth, so the return of a line's last item gets what is
 * left of the line, not what is left of the money booked on it.
 */
final class Version20261110217000 extends Migration
{
    public function getDescription(): string
    {
        return 'What returns gave back of each line';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('returns', 'line_values')) {
            $this->addSql('ALTER TABLE {prefix}returns ADD line_values JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}returns DROP line_values');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
