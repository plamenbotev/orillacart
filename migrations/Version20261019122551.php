<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019122551 extends Migration
{
    public function getDescription(): string
    {
        return 'One table of SKUs, so the database refuses a second product or variation with one';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE {prefix}skus (sku VARCHAR(64) NOT NULL, product_id INT NOT NULL, variation_id INT DEFAULT NULL, INDEX IDX_3EDC16594584665A (product_id), INDEX IDX_3EDC16595182BFD8 (variation_id), PRIMARY KEY (sku)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}skus ADD CONSTRAINT FK_1255E53F0778647E FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE {prefix}skus ADD CONSTRAINT FK_1B6C4FD7F892D769 FOREIGN KEY (variation_id) REFERENCES {prefix}variations (id) ON DELETE CASCADE');
        // The SKUs there are; of two that clash already, the first stays claimed.
        $this->addSql('INSERT IGNORE INTO {prefix}skus (sku, product_id, variation_id) SELECT sku, id, NULL FROM {prefix}products WHERE sku IS NOT NULL ORDER BY id');
        $this->addSql('INSERT IGNORE INTO {prefix}skus (sku, product_id, variation_id) SELECT sku, product_id, id FROM {prefix}variations WHERE sku IS NOT NULL ORDER BY id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}skus DROP FOREIGN KEY FK_1255E53F0778647E');
        $this->addSql('ALTER TABLE {prefix}skus DROP FOREIGN KEY FK_1B6C4FD7F892D769');
        $this->addSql('DROP TABLE {prefix}skus');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
