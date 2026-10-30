<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Buy X get Y coupons and free gifts.
 */
final class Version20261021114443 extends Migration
{
    public function getDescription(): string
    {
        return 'Buy X get Y coupons and free gifts';
    }

    public function up(Schema $schema): void
    {
        // MySQL refuses a literal default on a JSON column: add them empty, fill them, then require them.
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('coupons', 'buy_quantity')) {
            $this->addSql('ALTER TABLE {prefix}coupons ADD buy_quantity INT DEFAULT 0 NOT NULL, ADD get_quantity INT DEFAULT 0 NOT NULL, ADD get_product_ids JSON DEFAULT NULL, ADD get_category_ids JSON DEFAULT NULL, ADD gift_product_id INT DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}coupons SET get_product_ids = \'[]\', get_category_ids = \'[]\' WHERE get_product_ids IS NULL');
        $this->addSql('ALTER TABLE {prefix}coupons MODIFY get_product_ids JSON NOT NULL, MODIFY get_category_ids JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons DROP buy_quantity, DROP get_quantity, DROP get_product_ids, DROP get_category_ids, DROP gift_product_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
