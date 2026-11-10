<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A logged problem is marked with whom it was about, so erasing a person finds it, and its message no longer has
 * their email. The problems kept so far were written with emails in them and named by their whole text: they go.
 */
final class Version20261110216000 extends Migration
{
    public function getDescription(): string
    {
        return 'Whom a logged problem is about';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('problems', 'about')) {
            $this->addSql('DELETE FROM {prefix}problems');
            $this->addSql("ALTER TABLE {prefix}problems ADD about VARCHAR(255) DEFAULT '' NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}problems DROP about');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
