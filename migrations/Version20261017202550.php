<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017202550 extends Migration
{
    public function getDescription(): string
    {
        return 'Order notes, product downloads and download grants';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('download_grants')) {
            $this->addSql('CREATE TABLE {prefix}download_grants (id INT AUTO_INCREMENT NOT NULL, downloads INT NOT NULL, token VARCHAR(64) NOT NULL, line_position INT NOT NULL, file_id INT NOT NULL, name VARCHAR(200) NOT NULL, storage_key VARCHAR(32) NOT NULL, mime_type VARCHAR(100) NOT NULL, remaining INT DEFAULT NULL, expires_at DATETIME DEFAULT NULL, order_id INT NOT NULL, INDEX download_storage (storage_key), UNIQUE INDEX download_token (token), INDEX IDX_71FD89188D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('order_notes')) {
            $this->addSql('CREATE TABLE {prefix}order_notes (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, author VARCHAR(20) NOT NULL, for_customer TINYINT NOT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, order_id INT NOT NULL, INDEX IDX_9D7137578D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('product_downloads')) {
            $this->addSql('CREATE TABLE {prefix}product_downloads (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, storage_key VARCHAR(32) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, product_id INT NOT NULL, INDEX IDX_A6CD9014584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasForeignKey('download_grants', 'FK_71FD89188D9F6D38')) {
            $this->addSql('ALTER TABLE {prefix}download_grants ADD CONSTRAINT FK_71FD89188D9F6D38 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('order_notes', 'FK_9D7137578D9F6D38')) {
            $this->addSql('ALTER TABLE {prefix}order_notes ADD CONSTRAINT FK_9D7137578D9F6D38 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('product_downloads', 'FK_A6CD9014584665A')) {
            $this->addSql('ALTER TABLE {prefix}product_downloads ADD CONSTRAINT FK_A6CD9014584665A FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        }
        // Lines of orders placed before this version have no downloads.
        if (!$this->hasColumn('order_lines', 'downloads')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD downloads JSON DEFAULT NULL, ADD download_limit INT DEFAULT NULL, ADD download_expiry_days INT DEFAULT NULL');
        }
        $this->addSql("UPDATE {prefix}order_lines SET downloads = '[]' WHERE downloads IS NULL");
        $this->addSql('ALTER TABLE {prefix}order_lines MODIFY downloads JSON NOT NULL');
        if (!$this->hasColumn('products', 'download_limit')) {
            $this->addSql('ALTER TABLE {prefix}products ADD download_limit INT DEFAULT NULL, ADD download_expiry_days INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}download_grants DROP FOREIGN KEY FK_71FD89188D9F6D38');
        $this->addSql('ALTER TABLE {prefix}order_notes DROP FOREIGN KEY FK_9D7137578D9F6D38');
        $this->addSql('ALTER TABLE {prefix}product_downloads DROP FOREIGN KEY FK_A6CD9014584665A');
        $this->addSql('DROP TABLE {prefix}download_grants');
        $this->addSql('DROP TABLE {prefix}order_notes');
        $this->addSql('DROP TABLE {prefix}product_downloads');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP downloads, DROP download_limit, DROP download_expiry_days');
        $this->addSql('ALTER TABLE {prefix}products DROP download_limit, DROP download_expiry_days');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
