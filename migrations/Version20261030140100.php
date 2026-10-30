<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Newsletter double opt-in: a sign-up waits for its owner to confirm it from the email, and keeps when they did.
 * Those signed up before stay subscribed: the column starts false for them.
 */
final class Version20261030140100 extends Migration
{
    public function getDescription(): string
    {
        return 'Newsletter sign-ups that wait for their owner to confirm them from the email, and when they did';
    }

    public function up(Schema $schema): void
    {
        // One statement, guarded, so a run cut off goes on when it runs again.
        if (!$this->hasColumn('newsletter_subscribers', 'pending')) {
            $this->addSql('ALTER TABLE {prefix}newsletter_subscribers ADD pending TINYINT DEFAULT 0 NOT NULL, ADD confirmed_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}newsletter_subscribers DROP pending, DROP confirmed_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
