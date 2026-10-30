<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017192836 extends Migration
{
    public function getDescription(): string
    {
        return 'Carriers and shipping rates, payment methods and payments, carts, and orders';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('carriers')) {
            $this->addSql('CREATE TABLE {prefix}carriers (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, active TINYINT NOT NULL, position INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('cart_lines')) {
            $this->addSql('CREATE TABLE {prefix}cart_lines (id INT AUTO_INCREMENT NOT NULL, option_ids JSON NOT NULL, quantity INT NOT NULL, product_id INT NOT NULL, position INT NOT NULL, cart_id INT NOT NULL, INDEX IDX_3E8DA94D1AD5CDBF (cart_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('carts')) {
            $this->addSql('CREATE TABLE {prefix}carts (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL, customer_id INT DEFAULT NULL, INDEX cart_customer (customer_id), INDEX cart_updated (updated_at), UNIQUE INDEX cart_token (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('order_lines')) {
            $this->addSql('CREATE TABLE {prefix}order_lines (id INT AUTO_INCREMENT NOT NULL, product_id INT NOT NULL, variation_id INT DEFAULT NULL, option_ids JSON NOT NULL, options JSON NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(64) DEFAULT NULL, quantity INT NOT NULL, unit_price NUMERIC(19, 4) NOT NULL, total NUMERIC(19, 4) NOT NULL, tax NUMERIC(19, 4) NOT NULL, ships TINYINT NOT NULL, stock_taken JSON NOT NULL, position INT NOT NULL, order_id INT NOT NULL, INDEX IDX_D36B1D4D8D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('orders')) {
            $this->addSql('CREATE TABLE {prefix}orders (id INT AUTO_INCREMENT NOT NULL, order_key VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, customer_id INT DEFAULT NULL, email VARCHAR(200) NOT NULL, shipping_rate_id INT DEFAULT NULL, shipping_name VARCHAR(200) DEFAULT NULL, shipping_total NUMERIC(19, 4) NOT NULL, shipping_tax NUMERIC(19, 4) NOT NULL, taxes JSON NOT NULL, subtotal NUMERIC(19, 4) NOT NULL, items_tax NUMERIC(19, 4) NOT NULL, total NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, payment_gateway VARCHAR(40) DEFAULT NULL, payment_name VARCHAR(100) NOT NULL, payment_note LONGTEXT NOT NULL, transaction_id VARCHAR(100) DEFAULT NULL, note LONGTEXT NOT NULL, placed_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, billing_first_name VARCHAR(200) DEFAULT NULL, billing_last_name VARCHAR(200) DEFAULT NULL, billing_street VARCHAR(200) DEFAULT NULL, billing_city VARCHAR(200) DEFAULT NULL, billing_postcode VARCHAR(20) DEFAULT NULL, billing_country VARCHAR(2) DEFAULT NULL, billing_state VARCHAR(3) DEFAULT NULL, billing_phone VARCHAR(40) DEFAULT NULL, shipping_first_name VARCHAR(200) DEFAULT NULL, shipping_last_name VARCHAR(200) DEFAULT NULL, shipping_street VARCHAR(200) DEFAULT NULL, shipping_city VARCHAR(200) DEFAULT NULL, shipping_postcode VARCHAR(20) DEFAULT NULL, shipping_country VARCHAR(2) DEFAULT NULL, shipping_state VARCHAR(3) DEFAULT NULL, shipping_phone VARCHAR(40) DEFAULT NULL, INDEX order_status (status, placed_at), INDEX order_customer (customer_id), UNIQUE INDEX order_key (order_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('payment_methods')) {
            $this->addSql('CREATE TABLE {prefix}payment_methods (id INT AUTO_INCREMENT NOT NULL, gateway VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL, active TINYINT NOT NULL, position INT NOT NULL, countries JSON NOT NULL, instructions LONGTEXT NOT NULL, receipt_note LONGTEXT NOT NULL, settings JSON NOT NULL, UNIQUE INDEX payment_method_gateway (gateway), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('payments')) {
            $this->addSql('CREATE TABLE {prefix}payments (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(19, 4) NOT NULL, status VARCHAR(20) NOT NULL, reference VARCHAR(100) DEFAULT NULL, transaction_id VARCHAR(100) DEFAULT NULL, settled_at DATETIME DEFAULT NULL, order_id INT NOT NULL, gateway VARCHAR(40) NOT NULL, currency VARCHAR(3) NOT NULL, receipt_url VARCHAR(2000) NOT NULL, created_at DATETIME NOT NULL, INDEX payment_order (order_id), UNIQUE INDEX payment_reference (gateway, reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('shipping_rates')) {
            $this->addSql('CREATE TABLE {prefix}shipping_rates (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, price NUMERIC(19, 4) NOT NULL, per_item TINYINT NOT NULL, regions JSON NOT NULL, taxable TINYINT NOT NULL, tax_group_id INT DEFAULT NULL, position INT NOT NULL, weight_min NUMERIC(18, 4) NOT NULL, weight_max NUMERIC(18, 4) DEFAULT NULL, volume_min NUMERIC(18, 4) NOT NULL, volume_max NUMERIC(18, 4) DEFAULT NULL, length_min NUMERIC(18, 4) NOT NULL, length_max NUMERIC(18, 4) DEFAULT NULL, width_min NUMERIC(18, 4) NOT NULL, width_max NUMERIC(18, 4) DEFAULT NULL, height_min NUMERIC(18, 4) NOT NULL, height_max NUMERIC(18, 4) DEFAULT NULL, subtotal_min NUMERIC(18, 4) NOT NULL, subtotal_max NUMERIC(18, 4) DEFAULT NULL, carrier_id INT NOT NULL, INDEX IDX_F527D6721DFC797 (carrier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasForeignKey('cart_lines', 'FK_3E8DA94D1AD5CDBF')) {
            $this->addSql('ALTER TABLE {prefix}cart_lines ADD CONSTRAINT FK_3E8DA94D1AD5CDBF FOREIGN KEY (cart_id) REFERENCES {prefix}carts (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('order_lines', 'FK_D36B1D4D8D9F6D38')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD CONSTRAINT FK_D36B1D4D8D9F6D38 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('shipping_rates', 'FK_F527D6721DFC797')) {
            $this->addSql('ALTER TABLE {prefix}shipping_rates ADD CONSTRAINT FK_F527D6721DFC797 FOREIGN KEY (carrier_id) REFERENCES {prefix}carriers (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP FOREIGN KEY FK_3E8DA94D1AD5CDBF');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP FOREIGN KEY FK_D36B1D4D8D9F6D38');
        $this->addSql('ALTER TABLE {prefix}shipping_rates DROP FOREIGN KEY FK_F527D6721DFC797');
        $this->addSql('DROP TABLE {prefix}carriers');
        $this->addSql('DROP TABLE {prefix}cart_lines');
        $this->addSql('DROP TABLE {prefix}carts');
        $this->addSql('DROP TABLE {prefix}order_lines');
        $this->addSql('DROP TABLE {prefix}orders');
        $this->addSql('DROP TABLE {prefix}payment_methods');
        $this->addSql('DROP TABLE {prefix}payments');
        $this->addSql('DROP TABLE {prefix}shipping_rates');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
