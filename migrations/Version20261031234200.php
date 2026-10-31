<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031234200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persiste IDs dos eventos de webhook Asaas para evitar processamento duplicado';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('asaas_webhook_events')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE asaas_webhook_events (
                    event_id VARCHAR(255) NOT NULL,
                    event_type VARCHAR(100) NOT NULL,
                    payment_id VARCHAR(255) NOT NULL,
                    received_at DATETIME NOT NULL,
                    PRIMARY KEY (event_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('asaas_webhook_events')) {
            $this->addSql('DROP TABLE asaas_webhook_events');
        }
    }
}
