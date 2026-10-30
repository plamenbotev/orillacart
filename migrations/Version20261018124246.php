<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018124246 extends Migration
{
    public function getDescription(): string
    {
        return 'Bundles: what a product is made of, and what an order line held';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('order_lines', 'contents')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD contents JSON DEFAULT NULL');
        }
        if (!$this->hasColumn('products', 'bundle')) {
            $this->addSql('ALTER TABLE {prefix}products ADD bundle JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_lines DROP contents');
        $this->addSql('ALTER TABLE {prefix}products DROP bundle');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
