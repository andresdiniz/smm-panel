<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds Messenger worker heartbeats and payment reconciliation retry metadata';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('messenger_worker_heartbeats')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE messenger_worker_heartbeats (
                    worker_id CHAR(64) NOT NULL,
                    transport_name VARCHAR(64) NOT NULL,
                    host_name VARCHAR(128) NOT NULL,
                    process_id INT NOT NULL,
                    started_at DATETIME NOT NULL,
                    last_seen_at DATETIME NOT NULL,
                    last_processed_at DATETIME DEFAULT NULL,
                    message_count BIGINT NOT NULL DEFAULT 0,
                    last_message_class VARCHAR(255) DEFAULT NULL,
                    last_error_at DATETIME DEFAULT NULL,
                    last_error_class VARCHAR(255) DEFAULT NULL,
                    INDEX idx_messenger_worker_transport_seen (transport_name, last_seen_at),
                    PRIMARY KEY(worker_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        }

        $paymentTable = $schema->getTable('payments');
        if (!$paymentTable->hasColumn('last_reconciled_at')) {
            $this->addSql('ALTER TABLE payments ADD last_reconciled_at DATETIME DEFAULT NULL');
        }
        if (!$paymentTable->hasColumn('reconciliation_attempts')) {
            $this->addSql('ALTER TABLE payments ADD reconciliation_attempts INT NOT NULL DEFAULT 0');
        }
        if (!$paymentTable->hasIndex('idx_payments_reconcile')) {
            $this->addSql('CREATE INDEX idx_payments_reconcile ON payments (status, type, last_reconciled_at, created_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('messenger_worker_heartbeats')) {
            $this->addSql('DROP TABLE messenger_worker_heartbeats');
        }

        if (!$schema->hasTable('payments')) {
            return;
        }

        $paymentTable = $schema->getTable('payments');
        if ($paymentTable->hasIndex('idx_payments_reconcile')) {
            $this->addSql('DROP INDEX idx_payments_reconcile ON payments');
        }
        if ($paymentTable->hasColumn('last_reconciled_at')) {
            $this->addSql('ALTER TABLE payments DROP last_reconciled_at');
        }
        if ($paymentTable->hasColumn('reconciliation_attempts')) {
            $this->addSql('ALTER TABLE payments DROP reconciliation_attempts');
        }
    }
}
