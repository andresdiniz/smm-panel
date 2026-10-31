<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela de histórico das execuções de tarefas agendadas';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('cron_logs')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE cron_logs (
                id INT AUTO_INCREMENT NOT NULL,
                command VARCHAR(128) NOT NULL,
                success TINYINT(1) NOT NULL,
                duration_ms INT DEFAULT NULL,
                items_processed INT DEFAULT NULL,
                error_message LONGTEXT DEFAULT NULL,
                output LONGTEXT DEFAULT NULL,
                context LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
                started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_cron_logs_command (command),
                INDEX idx_cron_logs_started_at (started_at),
                INDEX idx_cron_logs_success (success),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cron_logs')) {
            $this->addSql('DROP TABLE cron_logs');
        }
    }
}
