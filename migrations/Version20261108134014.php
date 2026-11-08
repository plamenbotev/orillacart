<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Extensions (design 56, PLAN 331): the versions kept of each extension's files, the log of its runs, and the hash
 * of the shop's template an override replaced when it was saved, for the stale mark.
 */
final class Version20261108134014 extends Migration
{
    public function getDescription(): string
    {
        return 'Extension versions, run log and override originals';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasTable('extension_runs')) {
            $this->addSql('CREATE TABLE {prefix}extension_runs (id INT AUTO_INCREMENT NOT NULL, extension VARCHAR(40) NOT NULL, what VARCHAR(120) NOT NULL, outcome VARCHAR(20) NOT NULL, message LONGTEXT DEFAULT NULL, used JSON NOT NULL, ran_at DATETIME NOT NULL, INDEX extension_run_recent (extension, id), INDEX extension_run_at (ran_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('package_versions')) {
            $this->addSql('CREATE TABLE {prefix}package_versions (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, note VARCHAR(120) NOT NULL, files JSON NOT NULL, checksum VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, package_id INT NOT NULL, UNIQUE INDEX package_version_number (package_id, number), INDEX IDX_2DEA6A80F44CABFF (package_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE {prefix}package_versions ADD CONSTRAINT FK_2DEA6A80F44CABFF FOREIGN KEY (package_id) REFERENCES {prefix}packages (id) ON DELETE CASCADE');
        }
        if (!$this->hasColumn('package_files', 'original_hash')) {
            $this->addSql('ALTER TABLE {prefix}package_files ADD original_hash VARCHAR(16) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}package_versions DROP FOREIGN KEY FK_2DEA6A80F44CABFF');
        $this->addSql('DROP TABLE {prefix}extension_runs');
        $this->addSql('DROP TABLE {prefix}package_versions');
        $this->addSql('ALTER TABLE {prefix}package_files DROP original_hash');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
