<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018003452 extends Migration
{
    public function getDescription(): string
    {
        return 'Options can change the price by a percentage';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}attribute_options ADD adjustment_kind VARCHAR(10) DEFAULT \'amount\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}attribute_options DROP adjustment_kind');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
