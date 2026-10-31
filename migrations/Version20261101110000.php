<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Lines an add-on keeps in the cart, and sells as order lines of their type: the cart line type's id and label,
 * and whether the shopper may remove them.
 */
final class Version20261101110000 extends Migration
{
    public function getDescription(): string
    {
        return 'Add-on cart line types';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('cart_lines', 'add_on_type')) {
            $this->addSql('ALTER TABLE {prefix}cart_lines ADD add_on_type VARCHAR(40) DEFAULT NULL, ADD add_on_label VARCHAR(100) DEFAULT NULL, ADD removable TINYINT DEFAULT NULL');
        }
        if (!$this->hasColumn('order_lines', 'add_on_type')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD add_on_type VARCHAR(40) DEFAULT NULL, ADD add_on_label VARCHAR(100) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_lines DROP add_on_type, DROP add_on_label');
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP add_on_type, DROP add_on_label, DROP removable');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
