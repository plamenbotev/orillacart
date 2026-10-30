<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The jobs' type index holds when each is due and held too, so what waits of one type (which indexing asks after
 * every batch) is counted from the index alone, not the whole table.
 */
final class Version20261030150100 extends Migration
{
    public function getDescription(): string
    {
        return 'The jobs counted by type from their index alone: (type, available_at, locked_until)';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off between the two statements goes on from the second when it runs again. The
        // new index starts with type, so the old one on type alone is of no more use.
        if (!$this->hasIndex('jobs', 'job_type_waiting')) {
            $this->addSql('CREATE INDEX job_type_waiting ON {prefix}jobs (type, available_at, locked_until)');
        }
        if ($this->hasIndex('jobs', 'job_type')) {
            $this->addSql('DROP INDEX job_type ON {prefix}jobs');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX job_type ON {prefix}jobs (type)');
        $this->addSql('DROP INDEX job_type_waiting ON {prefix}jobs');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
