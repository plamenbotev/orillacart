<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * OAuth: when a token was last rotated, so the refresh token it replaced, sent again within a minute by a client
 * that refreshed twice at once, is refused without ending the grant.
 */
final class Version20261030150000 extends Migration
{
    public function getDescription(): string
    {
        return 'When an OAuth token was last rotated, so a parallel refresh is refused without ending the grant';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off goes on when it runs again.
        if (!$this->hasColumn('oauth_tokens', 'rotated_at')) {
            $this->addSql('ALTER TABLE {prefix}oauth_tokens ADD rotated_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}oauth_tokens DROP rotated_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
