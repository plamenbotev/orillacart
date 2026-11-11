<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * The recordings an extension's runs leave for its debugger (design 71): a test or console run debugged, or a live
 * capture. Kept a day.
 */
final class Version20261111230200 extends Migration
{
    public function getDescription(): string
    {
        return 'Recordings of extensions\' runs for their debugger';
    }

    public function up(Schema $schema): void
    {
        if ($this->hasTable('extension_recordings')) {
            return;
        }
        $this->addSql('CREATE TABLE {prefix}extension_recordings (id INT AUTO_INCREMENT NOT NULL, extension VARCHAR(40) NOT NULL, source VARCHAR(20) NOT NULL, what VARCHAR(120) NOT NULL, outcome VARCHAR(20) NOT NULL, message LONGTEXT DEFAULT NULL, data JSON NOT NULL, recorded_at DATETIME NOT NULL, INDEX extension_recording_recent (extension, id), INDEX extension_recording_at (recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}extension_recordings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
