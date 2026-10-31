<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Lines of their own type: a cart's lines are the shopper's products or items a discount gives, an order's are
 * items sold or given, shipping and fees. A given line keeps the discount that gave it; a cart, the discounts whose
 * items the shopper removed; a coupon, whether the shop adds the items it gives.
 */
final class Version20261101090000 extends Migration
{
    public function getDescription(): string
    {
        return 'Cart and order line types, items given by discounts';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again; the statements after
        // each guard find their work done then.
        if (!$this->hasColumn('cart_lines', 'type')) {
            $this->addSql("ALTER TABLE {prefix}cart_lines ADD type VARCHAR(20) DEFAULT 'product' NOT NULL, ADD given_code VARCHAR(40) DEFAULT NULL, ADD given_label VARCHAR(255) DEFAULT NULL");
        }
        $this->addSql('ALTER TABLE {prefix}cart_lines ALTER type DROP DEFAULT');
        if (!$this->hasColumn('carts', 'declined')) {
            $this->addSql('ALTER TABLE {prefix}carts ADD declined JSON DEFAULT NULL');
        }
        $this->addSql("UPDATE {prefix}carts SET declined = '[]' WHERE declined IS NULL");
        $this->addSql('ALTER TABLE {prefix}carts MODIFY declined JSON NOT NULL');
        if ($this->hasColumn('order_lines', 'kind')) {
            $this->addSql('ALTER TABLE {prefix}order_lines CHANGE kind type VARCHAR(20) NOT NULL');
        }
        $this->addSql('ALTER TABLE {prefix}order_lines ALTER type DROP DEFAULT');
        if (!$this->hasColumn('order_lines', 'given_code')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD given_code VARCHAR(40) DEFAULT NULL, ADD given_description VARCHAR(255) DEFAULT NULL');
        }
        if (!$this->hasColumn('coupons', 'adds_items')) {
            $this->addSql('ALTER TABLE {prefix}coupons ADD adds_items TINYINT DEFAULT 1 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons DROP adds_items');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP given_code, DROP given_description');
        $this->addSql("ALTER TABLE {prefix}order_lines CHANGE type kind VARCHAR(10) DEFAULT 'item' NOT NULL");
        $this->addSql('ALTER TABLE {prefix}carts DROP declined');
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP type, DROP given_code, DROP given_label');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
