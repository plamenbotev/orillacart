<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020052914 extends Migration
{
    public function getDescription(): string
    {
        return 'Stock movements: every move of a level, why, and for which order or by whom';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}stock_movements (id BIGINT AUTO_INCREMENT NOT NULL, item_kind VARCHAR(20) NOT NULL, item_id INT NOT NULL, stockroom_id INT NOT NULL, stockroom VARCHAR(100) NOT NULL, delta INT NOT NULL, level_after INT NOT NULL, reason VARCHAR(20) NOT NULL, order_id INT DEFAULT NULL, user_id INT DEFAULT NULL, moved_at DATETIME NOT NULL, INDEX movement_item (item_kind, item_id, moved_at), INDEX movement_stockroom (stockroom_id, moved_at), INDEX movement_order (order_id), INDEX movement_at (moved_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}stock_movements');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
