<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The reports' facts of each order, and a job that counts the orders placed before them.
 */
final class Version20261018143458 extends Migration
{
    public function getDescription(): string
    {
        return 'Report tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}report_order_coupons (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, day DATE NOT NULL, counted TINYINT NOT NULL, currency VARCHAR(3) NOT NULL, code VARCHAR(40) NOT NULL, amount NUMERIC(19, 4) NOT NULL, INDEX report_coupon_day (counted, currency, day), INDEX report_coupon_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}report_order_lines (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, day DATE NOT NULL, counted TINYINT NOT NULL, currency VARCHAR(3) NOT NULL, product_id INT NOT NULL, variation_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, quantity INT NOT NULL, net NUMERIC(19, 4) NOT NULL, INDEX report_line_day (counted, currency, day, product_id), INDEX report_line_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}report_orders (order_id INT NOT NULL, day DATE NOT NULL, placed_at DATETIME NOT NULL, counted TINYINT NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, customer_key VARCHAR(210) NOT NULL, customer_id INT DEFAULT NULL, customer_name VARCHAR(200) NOT NULL, country VARCHAR(2) NOT NULL, payment VARCHAR(100) NOT NULL, shipping VARCHAR(255) NOT NULL, items INT NOT NULL, gross NUMERIC(19, 4) NOT NULL, discount NUMERIC(19, 4) NOT NULL, shipping_total NUMERIC(19, 4) NOT NULL, tax NUMERIC(19, 4) NOT NULL, total NUMERIC(19, 4) NOT NULL, INDEX report_order_day (counted, currency, day), INDEX report_order_customer (customer_key, placed_at), PRIMARY KEY (order_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}report_refunds (refund_id INT NOT NULL, order_id INT NOT NULL, day DATE NOT NULL, currency VARCHAR(3) NOT NULL, amount NUMERIC(19, 4) NOT NULL, INDEX report_refund_day (currency, day), INDEX report_refund_order (order_id), PRIMARY KEY (refund_id)) DEFAULT CHARACTER SET utf8mb4');
        // The orders placed so far are counted in the background, a thousand at a time.
        $this->addSql('INSERT INTO {prefix}jobs (queue, type, unique_key, payload, available_at, created_at, version, attempts) VALUES (?, ?, NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 0)', ['reports', 'reports.rebuild', '{"after":0}']);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}report_order_coupons');
        $this->addSql('DROP TABLE {prefix}report_order_lines');
        $this->addSql('DROP TABLE {prefix}report_orders');
        $this->addSql('DROP TABLE {prefix}report_refunds');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
