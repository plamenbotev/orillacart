<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261022070509 extends Migration
{
    public function getDescription(): string
    {
        return 'Who sent each upload, for their own limit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}form_uploads ADD sender VARCHAR(64) DEFAULT \'\' NOT NULL');
        $this->addSql('CREATE INDEX form_upload_sender ON {prefix}form_uploads (sender, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX form_upload_sender ON {prefix}form_uploads');
        $this->addSql('ALTER TABLE {prefix}form_uploads DROP sender');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
