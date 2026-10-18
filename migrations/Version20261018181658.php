<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Email jobs remember that their mail went out, so a job run again does not send it twice.
 */
final class Version20261018181658 extends Migration
{
    public function getDescription(): string
    {
        return 'Email jobs remember that their mail went out';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}sent_emails (job VARCHAR(80) NOT NULL, sent_at DATETIME NOT NULL, INDEX sent_email_at (sent_at), PRIMARY KEY (job)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}sent_emails');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
