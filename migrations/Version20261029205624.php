<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029205624 extends Migration
{
    public function getDescription(): string
    {
        return 'What a translation service last gave for each word, so a fix made in the shop stays until its translators change theirs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}translation_service_copies (id BIGINT AUTO_INCREMENT NOT NULL, object_type VARCHAR(40) NOT NULL, object_id INT NOT NULL, field VARCHAR(100) NOT NULL, locale VARCHAR(20) NOT NULL, value LONGTEXT NOT NULL, UNIQUE INDEX service_copy_field (object_type, object_id, field, locale), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}translation_service_copies');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
