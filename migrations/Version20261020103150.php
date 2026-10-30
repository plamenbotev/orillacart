<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020103150 extends Migration
{
    public function getDescription(): string
    {
        return 'Returns customers ask for, and when orders first left for the customer';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('returns')) {
            $this->addSql('CREATE TABLE {prefix}returns (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, items JSON NOT NULL, comment LONGTEXT NOT NULL, answer LONGTEXT NOT NULL, answered_at DATETIME DEFAULT NULL, received_at DATETIME DEFAULT NULL, refunded_at DATETIME DEFAULT NULL, order_id INT NOT NULL, reason VARCHAR(20) NOT NULL, requested_at DATETIME NOT NULL, INDEX return_order (order_id), INDEX return_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasForeignKey('returns', 'FK_4A4FB3AF9F8AE6FE')) {
            $this->addSql('ALTER TABLE {prefix}returns ADD CONSTRAINT FK_4A4FB3AF9F8AE6FE FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
        }
        if (!$this->hasColumn('orders', 'fulfilled_at')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD fulfilled_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}returns DROP FOREIGN KEY FK_4A4FB3AF9F8AE6FE');
        $this->addSql('DROP TABLE {prefix}returns');
        $this->addSql('ALTER TABLE {prefix}orders DROP fulfilled_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
