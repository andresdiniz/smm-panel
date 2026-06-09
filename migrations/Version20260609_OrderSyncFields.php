<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona os campos necessários para o fluxo de polling de status de pedidos:
 *   - sync_attempts : contador de tentativas de consulta ao provider (polling exponencial)
 *   - completed_at  : timestamp de quando o pedido foi marcado como concluído/cancelado
 */
final class Version20260609_OrderSyncFields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona sync_attempts (INT) e completed_at (DATETIME NULL) na tabela orders';
    }

    public function up(Schema $schema): void
    {
        // sync_attempts: começa em 0, NOT NULL, sem afetar registros existentes
        $this->addSql(
            'ALTER TABLE orders ADD COLUMN sync_attempts INT NOT NULL DEFAULT 0'
        );

        // completed_at: nullable, registra quando o pedido finalizou (completed, partial ou cancelled)
        $this->addSql(
            'ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP COLUMN completed_at');
        $this->addSql('ALTER TABLE orders DROP COLUMN sync_attempts');
    }
}
