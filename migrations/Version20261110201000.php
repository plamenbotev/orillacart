<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A return keeps what it took back of the coupons whose least order what was kept no longer met (PLAN 438), so a
 * later return of the rest gives it again.
 */
final class Version20261110201000 extends Migration
{
    public function getDescription(): string
    {
        return 'What returns took back of coupons';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('returns', 'coupons_taken')) {
            $this->addSql('ALTER TABLE {prefix}returns ADD coupons_taken NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}returns DROP coupons_taken');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
