<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017171353 extends Migration
{
    public function getDescription(): string
    {
        return 'Products, attribute sets, variations, stockrooms and stock levels';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('attribute_options')) {
            $this->addSql('CREATE TABLE {prefix}attribute_options (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, adjustment NUMERIC(19, 4) NOT NULL, position INT NOT NULL, attribute_id INT NOT NULL, INDEX IDX_7416687AB6E62EFA (attribute_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('attribute_sets')) {
            $this->addSql('CREATE TABLE {prefix}attribute_sets (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('attributes')) {
            $this->addSql('CREATE TABLE {prefix}attributes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, required TINYINT NOT NULL, price_hidden TINYINT NOT NULL, position INT NOT NULL, product_id INT DEFAULT NULL, set_id INT DEFAULT NULL, INDEX IDX_1FFDD6A34584665A (product_id), INDEX IDX_1FFDD6A310FB0D18 (set_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('products')) {
            $this->addSql('CREATE TABLE {prefix}products (type VARCHAR(20) NOT NULL, sku VARCHAR(64) DEFAULT NULL, taxable TINYINT NOT NULL, tax_group_id INT DEFAULT NULL, stock_mode VARCHAR(20) NOT NULL, purchasable TINYINT NOT NULL, featured TINYINT NOT NULL, weight NUMERIC(12, 4) DEFAULT NULL, length NUMERIC(12, 4) DEFAULT NULL, width NUMERIC(12, 4) DEFAULT NULL, height NUMERIC(12, 4) DEFAULT NULL, id INT NOT NULL, price NUMERIC(19, 4) NOT NULL, sale_price NUMERIC(19, 4) DEFAULT NULL, sale_from DATETIME DEFAULT NULL, sale_until DATETIME DEFAULT NULL, UNIQUE INDEX product_sku (sku), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('product_attribute_sets')) {
            $this->addSql('CREATE TABLE {prefix}product_attribute_sets (product_id INT NOT NULL, set_id INT NOT NULL, INDEX IDX_4D6FADC54584665A (product_id), INDEX IDX_4D6FADC510FB0D18 (set_id), PRIMARY KEY (product_id, set_id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('stock_levels')) {
            $this->addSql('CREATE TABLE {prefix}stock_levels (id INT AUTO_INCREMENT NOT NULL, item_kind VARCHAR(20) NOT NULL, item_id INT NOT NULL, quantity INT NOT NULL, stockroom_id INT NOT NULL, INDEX item (item_kind, item_id), UNIQUE INDEX stockroom_item (stockroom_id, item_kind, item_id), INDEX IDX_871EC79767024586 (stockroom_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('stockrooms')) {
            $this->addSql('CREATE TABLE {prefix}stockrooms (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT NOT NULL, min_delivery_days INT NOT NULL, max_delivery_days INT NOT NULL, active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('variations')) {
            $this->addSql('CREATE TABLE {prefix}variations (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(64) DEFAULT NULL, stock_mode VARCHAR(20) NOT NULL, price NUMERIC(19, 4) NOT NULL, sale_price NUMERIC(19, 4) DEFAULT NULL, sale_from DATETIME DEFAULT NULL, sale_until DATETIME DEFAULT NULL, product_id INT NOT NULL, UNIQUE INDEX variation_sku (sku), INDEX IDX_75D5178D4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('variation_options')) {
            $this->addSql('CREATE TABLE {prefix}variation_options (variation_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_940A94085182BFD8 (variation_id), INDEX IDX_940A9408A7C41D6F (option_id), PRIMARY KEY (variation_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasForeignKey('attribute_options', 'FK_7416687AB6E62EFA')) {
            $this->addSql('ALTER TABLE {prefix}attribute_options ADD CONSTRAINT FK_7416687AB6E62EFA FOREIGN KEY (attribute_id) REFERENCES {prefix}attributes (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('attributes', 'FK_1FFDD6A34584665A')) {
            $this->addSql('ALTER TABLE {prefix}attributes ADD CONSTRAINT FK_1FFDD6A34584665A FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('attributes', 'FK_1FFDD6A310FB0D18')) {
            $this->addSql('ALTER TABLE {prefix}attributes ADD CONSTRAINT FK_1FFDD6A310FB0D18 FOREIGN KEY (set_id) REFERENCES {prefix}attribute_sets (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('product_attribute_sets', 'FK_4D6FADC54584665A')) {
            $this->addSql('ALTER TABLE {prefix}product_attribute_sets ADD CONSTRAINT FK_4D6FADC54584665A FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('product_attribute_sets', 'FK_4D6FADC510FB0D18')) {
            $this->addSql('ALTER TABLE {prefix}product_attribute_sets ADD CONSTRAINT FK_4D6FADC510FB0D18 FOREIGN KEY (set_id) REFERENCES {prefix}attribute_sets (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('stock_levels', 'FK_871EC79767024586')) {
            $this->addSql('ALTER TABLE {prefix}stock_levels ADD CONSTRAINT FK_871EC79767024586 FOREIGN KEY (stockroom_id) REFERENCES {prefix}stockrooms (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('variations', 'FK_75D5178D4584665A')) {
            $this->addSql('ALTER TABLE {prefix}variations ADD CONSTRAINT FK_75D5178D4584665A FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('variation_options', 'FK_940A94085182BFD8')) {
            $this->addSql('ALTER TABLE {prefix}variation_options ADD CONSTRAINT FK_940A94085182BFD8 FOREIGN KEY (variation_id) REFERENCES {prefix}variations (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('variation_options', 'FK_940A9408A7C41D6F')) {
            $this->addSql('ALTER TABLE {prefix}variation_options ADD CONSTRAINT FK_940A9408A7C41D6F FOREIGN KEY (option_id) REFERENCES {prefix}attribute_options (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}attribute_options DROP FOREIGN KEY FK_7416687AB6E62EFA');
        $this->addSql('ALTER TABLE {prefix}attributes DROP FOREIGN KEY FK_1FFDD6A34584665A');
        $this->addSql('ALTER TABLE {prefix}attributes DROP FOREIGN KEY FK_1FFDD6A310FB0D18');
        $this->addSql('ALTER TABLE {prefix}product_attribute_sets DROP FOREIGN KEY FK_4D6FADC54584665A');
        $this->addSql('ALTER TABLE {prefix}product_attribute_sets DROP FOREIGN KEY FK_4D6FADC510FB0D18');
        $this->addSql('ALTER TABLE {prefix}stock_levels DROP FOREIGN KEY FK_871EC79767024586');
        $this->addSql('ALTER TABLE {prefix}variations DROP FOREIGN KEY FK_75D5178D4584665A');
        $this->addSql('ALTER TABLE {prefix}variation_options DROP FOREIGN KEY FK_940A94085182BFD8');
        $this->addSql('ALTER TABLE {prefix}variation_options DROP FOREIGN KEY FK_940A9408A7C41D6F');
        $this->addSql('DROP TABLE {prefix}attribute_options');
        $this->addSql('DROP TABLE {prefix}attribute_sets');
        $this->addSql('DROP TABLE {prefix}attributes');
        $this->addSql('DROP TABLE {prefix}products');
        $this->addSql('DROP TABLE {prefix}product_attribute_sets');
        $this->addSql('DROP TABLE {prefix}stock_levels');
        $this->addSql('DROP TABLE {prefix}stockrooms');
        $this->addSql('DROP TABLE {prefix}variations');
        $this->addSql('DROP TABLE {prefix}variation_options');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
