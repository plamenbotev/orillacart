<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028230026 extends Migration
{
    public function getDescription(): string
    {
        return 'Translations of the shop\'s own words: attribute and option names, and more to come';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}translations (id BIGINT AUTO_INCREMENT NOT NULL, object_type VARCHAR(40) NOT NULL, object_id INT NOT NULL, field VARCHAR(40) NOT NULL, locale VARCHAR(20) NOT NULL, value LONGTEXT NOT NULL, INDEX translation_locale (object_type, locale), UNIQUE INDEX translation_field (object_type, object_id, field, locale), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}translations');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
