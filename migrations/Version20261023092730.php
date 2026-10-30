<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Exemption certificates customers send wait for the shop to check them, with their copy; approved ones remind
 * the customer before they run out.
 */
final class Version20261023092730 extends Migration
{
    public function getDescription(): string
    {
        return 'Tax exemptions keep their review, copy and reminder';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('tax_exemptions', 'status')) {
            $this->addSql('ALTER TABLE {prefix}tax_exemptions ADD status VARCHAR(20) NOT NULL, ADD refusal VARCHAR(200) DEFAULT NULL, ADD reviewed_at DATETIME DEFAULT NULL, ADD reminded_at DATETIME DEFAULT NULL, ADD file_key VARCHAR(64) DEFAULT NULL, ADD file_name VARCHAR(200) DEFAULT NULL, ADD file_type VARCHAR(100) DEFAULT NULL');
        }
        // Those the shop entered itself hold as they were.
        $this->addSql("UPDATE {prefix}tax_exemptions SET status = 'approved' WHERE status = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}tax_exemptions DROP status, DROP refusal, DROP reviewed_at, DROP reminded_at, DROP file_key, DROP file_name, DROP file_type');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
