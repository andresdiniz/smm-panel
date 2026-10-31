<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608_OrderLog extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela order_logs para rastrear cada retorno do provider por pedido';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE order_logs (
                id           INT          NOT NULL AUTO_INCREMENT,
                order_id     INT          NULL,
                provider     VARCHAR(64)  NOT NULL,
                action       VARCHAR(32)  NOT NULL,
                http_status  SMALLINT     NULL,
                response_body LONGTEXT    NULL COMMENT '(DC2Type:json)',
                error_message TEXT        NULL,
                elapsed_ms   INT          NULL,
                created_at   DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_order_logs_order    (order_id),
                INDEX idx_order_logs_provider (provider),
                INDEX idx_order_logs_action   (action),
                CONSTRAINT fk_order_logs_order
                    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_logs');
    }
}
