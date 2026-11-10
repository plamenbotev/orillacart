<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A download keeps how far into its file it was sent, so a resume goes on from there rather than from the start
 * (PLAN 433). Downloads counted before are taken as sent in full: their resumes count as new downloads.
 */
final class Version20261110202000 extends Migration
{
    public function getDescription(): string
    {
        return 'How far downloads were sent';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('download_grants', 'sent_until')) {
            $this->addSql('ALTER TABLE {prefix}download_grants ADD sent_until BIGINT DEFAULT 0 NOT NULL');
            $this->addSql('UPDATE {prefix}download_grants SET resumes_left = 0');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}download_grants DROP sent_until');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
