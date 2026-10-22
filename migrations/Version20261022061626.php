<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261022061626 extends Migration
{
    public function getDescription(): string
    {
        return 'Custom forms and the files shoppers send with them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}form_uploads (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, order_id INT DEFAULT NULL, held_at DATETIME DEFAULT NULL, storage_key VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, created_at DATETIME NOT NULL, INDEX form_upload_order (order_id), UNIQUE INDEX form_upload_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}forms (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, placement VARCHAR(20) NOT NULL, active TINYINT NOT NULL, position INT NOT NULL, scope VARCHAR(20) NOT NULL, category_ids JSON NOT NULL, product_ids JSON NOT NULL, fields JSON NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}form_uploads');
        $this->addSql('DROP TABLE {prefix}forms');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
