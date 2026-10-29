<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029072129 extends Migration
{
    public function getDescription(): string
    {
        return 'Email templates for one of the site\'s languages; longer translated field names, for forms\' choices';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}email_templates ADD locale VARCHAR(20) DEFAULT \'\' NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (name, locale)');
        $this->addSql('ALTER TABLE {prefix}translations CHANGE field field VARCHAR(100) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}email_templates DROP locale, DROP PRIMARY KEY, ADD PRIMARY KEY (name)');
        $this->addSql('ALTER TABLE {prefix}translations CHANGE field field VARCHAR(40) NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
