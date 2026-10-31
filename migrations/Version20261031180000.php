<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Coupons shown to shoppers on the promotion blocks: the automatic discounts there are, which customers already
 * see on the cart, are shown; codes stay hidden until the shop chooses to show one.
 */
final class Version20261031180000 extends Migration
{
    public function getDescription(): string
    {
        return 'Coupons shown to shoppers';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again; the update finds its
        // work done then, so it runs each time, as a run cut off before it would leave it undone.
        if (!$this->hasColumn('coupons', 'advertised')) {
            $this->addSql('ALTER TABLE {prefix}coupons ADD advertised TINYINT DEFAULT 0 NOT NULL');
        }
        $this->addSql('UPDATE {prefix}coupons SET advertised = 1 WHERE automatic = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons DROP advertised');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
