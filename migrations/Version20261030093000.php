<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * OAuth: the refresh token a rotation replaced is kept by its hash, so one presented again is told from an unknown
 * one and ends the grant. The rate limits count in rows of their own, one statement each, instead of transients.
 */
final class Version20261030093000 extends Migration
{
    public function getDescription(): string
    {
        return 'The refresh token a rotation replaced, kept by its hash so one presented again ends the grant; the rate limits counted atomically in rows of their own';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('oauth_tokens', 'previous_refresh_hash')) {
            $this->addSql('ALTER TABLE {prefix}oauth_tokens ADD previous_refresh_hash VARCHAR(64) DEFAULT NULL');
        }
        if (!$this->hasIndex('oauth_tokens', 'oauth_previous_refresh_hash')) {
            $this->addSql('CREATE INDEX oauth_previous_refresh_hash ON {prefix}oauth_tokens (previous_refresh_hash)');
        }
        if (!$this->hasTable('rate_limits')) {
            $this->addSql('CREATE TABLE {prefix}rate_limits (bucket VARCHAR(64) NOT NULL, hits INT NOT NULL, expires_at DATETIME NOT NULL, INDEX rate_limit_expires (expires_at), PRIMARY KEY (bucket)) DEFAULT CHARACTER SET utf8mb4');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}rate_limits');
        $this->addSql('DROP INDEX oauth_previous_refresh_hash ON {prefix}oauth_tokens');
        $this->addSql('ALTER TABLE {prefix}oauth_tokens DROP previous_refresh_hash');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
