<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018101729 extends Migration
{
    public function getDescription(): string
    {
        return 'One queue for every kind of background job, with failed jobs, hourly counts and workers; the search index queue moves onto it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}failed_jobs (id BIGINT AUTO_INCREMENT NOT NULL, queue VARCHAR(40) NOT NULL, type VARCHAR(80) NOT NULL, unique_key VARCHAR(150) DEFAULT NULL, payload LONGTEXT NOT NULL, error LONGTEXT NOT NULL, attempts INT NOT NULL, failed_at DATETIME NOT NULL, INDEX failed_job_when (failed_at), INDEX failed_job_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}job_stats (done INT NOT NULL, retried INT NOT NULL, failed INT NOT NULL, runtime_ms BIGINT NOT NULL, hour DATETIME NOT NULL, type VARCHAR(80) NOT NULL, PRIMARY KEY (hour, type)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}job_workers (seen_at DATETIME NOT NULL, working VARCHAR(80) DEFAULT NULL, jobs_done INT NOT NULL, memory BIGINT NOT NULL, name VARCHAR(100) NOT NULL, queues VARCHAR(255) NOT NULL, started_at DATETIME NOT NULL, INDEX job_worker_seen (seen_at), PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}jobs (id BIGINT AUTO_INCREMENT NOT NULL, version INT NOT NULL, attempts INT NOT NULL, locked_until DATETIME DEFAULT NULL, locked_by VARCHAR(100) DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, queue VARCHAR(40) NOT NULL, type VARCHAR(80) NOT NULL, unique_key VARCHAR(150) DEFAULT NULL, payload LONGTEXT NOT NULL, available_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX job_due (queue, available_at), INDEX job_available (available_at), INDEX job_type (type), UNIQUE INDEX job_unique (type, unique_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('DROP TABLE {prefix}index_jobs');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}index_jobs (version INT NOT NULL, attempts INT NOT NULL, locked_until DATETIME DEFAULT NULL, last_error VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_uca1400_ai_ci`, product_id INT NOT NULL, available_at DATETIME NOT NULL, INDEX index_job_due (available_at), PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('DROP TABLE {prefix}failed_jobs');
        $this->addSql('DROP TABLE {prefix}job_stats');
        $this->addSql('DROP TABLE {prefix}job_workers');
        $this->addSql('DROP TABLE {prefix}jobs');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
