<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018120446 extends Migration
{
    public function getDescription(): string
    {
        return 'Coupons: codes, their uses, and the discounts carts and orders carry';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('coupon_redemptions')) {
            $this->addSql('CREATE TABLE {prefix}coupon_redemptions (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(19, 4) NOT NULL, coupon_id INT NOT NULL, order_id INT NOT NULL, customer_id INT DEFAULT NULL, email VARCHAR(200) NOT NULL, redeemed_at DATETIME NOT NULL, INDEX redemption_customer (coupon_id, customer_id), INDEX redemption_email (coupon_id, email), INDEX redemption_of_order (order_id), UNIQUE INDEX redemption_order (coupon_id, order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('coupons')) {
            $this->addSql('CREATE TABLE {prefix}coupons (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, description VARCHAR(255) NOT NULL, kind VARCHAR(20) NOT NULL, amount NUMERIC(19, 4) NOT NULL, cap NUMERIC(19, 4) DEFAULT NULL, active TINYINT NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, min_subtotal NUMERIC(19, 4) DEFAULT NULL, max_subtotal NUMERIC(19, 4) DEFAULT NULL, min_quantity INT NOT NULL, countries JSON NOT NULL, product_ids JSON NOT NULL, category_ids JSON NOT NULL, brand_ids JSON NOT NULL, excluded_product_ids JSON NOT NULL, excluded_category_ids JSON NOT NULL, exclude_sale_items TINYINT NOT NULL, combines TINYINT NOT NULL, usage_limit INT DEFAULT NULL, per_customer_limit INT DEFAULT NULL, first_order_only TINYINT NOT NULL, uses INT NOT NULL, UNIQUE INDEX coupon_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        // Existing carts and orders get an empty list before the columns become required.
        if (!$this->hasColumn('carts', 'coupons')) {
            $this->addSql('ALTER TABLE {prefix}carts ADD coupons JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}carts SET coupons = \'[]\' WHERE coupons IS NULL');
        $this->addSql('ALTER TABLE {prefix}carts MODIFY coupons JSON NOT NULL');
        if (!$this->hasColumn('order_lines', 'discount')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD discount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        }
        if (!$this->hasColumn('orders', 'discount_total')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD discount_total NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD discounts JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}orders SET discounts = \'[]\' WHERE discounts IS NULL');
        $this->addSql('ALTER TABLE {prefix}orders MODIFY discounts JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}coupon_redemptions');
        $this->addSql('DROP TABLE {prefix}coupons');
        $this->addSql('ALTER TABLE {prefix}carts DROP coupons');
        $this->addSql('ALTER TABLE {prefix}orders DROP discount_total, DROP discounts');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP discount');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
