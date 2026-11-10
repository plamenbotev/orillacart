<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A download resumed only by whoever it was counted for, a few times (security review, PLAN 392).
 */
final class Version20261110160000 extends Migration
{
    public function getDescription(): string
    {
        return 'Who a download went to, and its resumes left';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('download_grants', 'last_downloaded_by')) {
            $this->addSql('ALTER TABLE {prefix}download_grants ADD last_downloaded_by VARCHAR(16) DEFAULT NULL, ADD resumes_left INT DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}download_grants DROP last_downloaded_by, DROP resumes_left');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
