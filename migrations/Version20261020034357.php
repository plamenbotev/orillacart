<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020034357 extends Migration
{
    public function getDescription(): string
    {
        return 'The email templates the shop writes in the admin';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}email_templates (source LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, updated_by INT DEFAULT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}email_templates');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
