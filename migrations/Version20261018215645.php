<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018215645 extends Migration
{
    public function getDescription(): string
    {
        return 'When an order\'s customer details were erased';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders ADD anonymised_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders DROP anonymised_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
