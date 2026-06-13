<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona as colunas faltantes em order_logs:
 *   - retry_count  (SMALLINT NOT NULL DEFAULT 0)
 *   - context      (LONGTEXT NULL / JSON)
 *   - índice em created_at
 *
 * A tabela foi criada originalmente pela Version20260608_OrderLog
 * sem essas colunas, causando SQLSTATE[42S22] em qualquer INSERT.
 */
final class Version20260613_OrderLogMissingColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona retry_count e context em order_logs; adiciona índice em created_at';
    }

    public function up(Schema $schema): void
    {
        // Adiciona retry_count após elapsed_ms
        $this->addSql(
            'ALTER TABLE order_logs
             ADD COLUMN retry_count SMALLINT NOT NULL DEFAULT 0 AFTER elapsed_ms'
        );

        // Adiciona context (JSON livre) após retry_count
        $this->addSql(
            "ALTER TABLE order_logs
             ADD COLUMN context LONGTEXT NULL COMMENT '(DC2Type:json)' AFTER retry_count"
        );

        // Índice em created_at (já declarado na entidade via #[ORM\Index])
        $this->addSql(
            'CREATE INDEX idx_order_logs_created_at ON order_logs (created_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_order_logs_created_at ON order_logs');
        $this->addSql('ALTER TABLE order_logs DROP COLUMN context');
        $this->addSql('ALTER TABLE order_logs DROP COLUMN retry_count');
    }
}
