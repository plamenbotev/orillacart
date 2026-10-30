<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261022063949 extends Migration
{
    public function getDescription(): string
    {
        return 'Answers to product forms on cart and order lines';
    }

    public function up(Schema $schema): void
    {
        // JSON columns take no default: added empty, filled, then required.
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('cart_lines', 'answers')) {
            $this->addSql('ALTER TABLE {prefix}cart_lines ADD answers JSON DEFAULT NULL');
        }
        $this->addSql("UPDATE {prefix}cart_lines SET answers = '[]' WHERE answers IS NULL");
        $this->addSql('ALTER TABLE {prefix}cart_lines MODIFY answers JSON NOT NULL');
        if (!$this->hasColumn('order_lines', 'personalisation')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD personalisation JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP answers');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP personalisation');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
