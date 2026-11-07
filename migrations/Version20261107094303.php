<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Extensions written in the script language (design 56): the packages of a site and their files. The compiled
 * classes are a cache under the cache folder, not in the database.
 */
final class Version20261107094303 extends Migration
{
    public function getDescription(): string
    {
        return 'Extension packages and their files';
    }

    public function up(Schema $schema): void
    {
        if ($this->hasTable('packages')) {
            return;
        }
        $this->addSql('CREATE TABLE {prefix}package_files (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, path VARCHAR(200) NOT NULL, package_id INT NOT NULL, UNIQUE INDEX package_file_path (package_id, path), INDEX IDX_808FCD99F44CABFF (package_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}packages (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL, version VARCHAR(40) DEFAULT \'\' NOT NULL, state VARCHAR(20) NOT NULL, granted JSON NOT NULL, enabled_checksum VARCHAR(16) DEFAULT \'\' NOT NULL, failures INT DEFAULT 0 NOT NULL, last_problem LONGTEXT DEFAULT NULL, settings JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX package_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}package_files ADD CONSTRAINT FK_808FCD99F44CABFF FOREIGN KEY (package_id) REFERENCES {prefix}packages (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}package_files DROP FOREIGN KEY FK_808FCD99F44CABFF');
        $this->addSql('DROP TABLE {prefix}package_files');
        $this->addSql('DROP TABLE {prefix}packages');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
