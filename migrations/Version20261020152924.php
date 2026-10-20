<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The newsletter's sign-ups.
 */
final class Version20261020152924 extends Migration
{
    public function getDescription(): string
    {
        return 'Newsletter subscribers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}newsletter_subscribers (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(190) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, consent VARCHAR(500) NOT NULL, source VARCHAR(20) NOT NULL, subscribed_at DATETIME NOT NULL, unsubscribed_at DATETIME DEFAULT NULL, customer_id INT DEFAULT NULL, INDEX newsletter_subscribers_in (unsubscribed_at), UNIQUE INDEX newsletter_subscriber_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}newsletter_subscribers');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
