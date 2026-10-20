<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020054138 extends Migration
{
    public function getDescription(): string
    {
        return 'Back in stock requests: whom to email when a sold-out item is back';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}back_in_stock (id INT AUTO_INCREMENT NOT NULL, item VARCHAR(40) NOT NULL, product_id INT NOT NULL, email VARCHAR(254) NOT NULL, locale VARCHAR(20) DEFAULT NULL, asked_at DATETIME NOT NULL, INDEX back_in_stock_email (email), INDEX back_in_stock_at (asked_at), UNIQUE INDEX back_in_stock_item_email (item, email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}back_in_stock');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
