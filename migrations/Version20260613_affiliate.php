<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613_affiliate extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sistema de afiliados: campos em users + tabela affiliate_commission (idempotente)';
    }

    public function up(Schema $schema): void
    {
        $usersTable = $schema->getTable('users');
        $columnNames = array_map(
            fn($c) => $c->getName(),
            $usersTable->getColumns()
        );

        // Adiciona apenas as colunas que ainda não existem
        $cols = [];
        if (!in_array('affiliate_code', $columnNames, true)) {
            $cols[] = 'ADD affiliate_code VARCHAR(16) DEFAULT NULL';
        }
        if (!in_array('affiliate_commission_rate', $columnNames, true)) {
            $cols[] = 'ADD affiliate_commission_rate DECIMAL(5,4) DEFAULT NULL';
        }
        if (!in_array('referred_by_id', $columnNames, true)) {
            $cols[] = 'ADD referred_by_id INT DEFAULT NULL';
        }

        if (!empty($cols)) {
            $this->addSql('ALTER TABLE users ' . implode(', ', $cols));
        }

        // Índice único — verifica antes
        $indexNames = array_map(fn($i) => $i->getName(), $usersTable->getIndexes());
        if (!in_array('UNIQ_1483A5E99F75D7B0', $indexNames, true)) {
            $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E99F75D7B0 ON users (affiliate_code)');
        }

        // FK referred_by — verifica antes
        $fkNames = array_map(fn($f) => $f->getName(), $usersTable->getForeignKeys());
        if (!in_array('FK_users_referred_by', $fkNames, true)) {
            $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_users_referred_by
                FOREIGN KEY (referred_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        // Tabela de comissões — só cria se não existir
        if (!$schema->hasTable('affiliate_commission')) {
            $this->addSql('
                CREATE TABLE affiliate_commission (
                    id              INT AUTO_INCREMENT NOT NULL,
                    affiliate_id    INT NOT NULL,
                    order_id        INT NOT NULL,
                    customer_id     INT NOT NULL,
                    amount          DECIMAL(10,2) NOT NULL,
                    rate            DECIMAL(5,4) NOT NULL,
                    status          VARCHAR(20) NOT NULL DEFAULT \'pending\',
                    created_at      DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                    paid_at         DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                    PRIMARY KEY(id),
                    INDEX IDX_aff_affiliate (affiliate_id),
                    INDEX IDX_aff_order (order_id),
                    INDEX IDX_aff_customer (customer_id),
                    INDEX IDX_aff_status (status),
                    CONSTRAINT FK_aff_affiliate FOREIGN KEY (affiliate_id) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT FK_aff_order     FOREIGN KEY (order_id)     REFERENCES `orders` (id) ON DELETE CASCADE,
                    CONSTRAINT FK_aff_customer  FOREIGN KEY (customer_id)  REFERENCES users (id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            ');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('affiliate_commission')) {
            $this->addSql('DROP TABLE affiliate_commission');
        }

        $usersTable = $schema->getTable('users');
        $fkNames    = array_map(fn($f) => $f->getName(), $usersTable->getForeignKeys());
        if (in_array('FK_users_referred_by', $fkNames, true)) {
            $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_users_referred_by');
        }

        $indexNames = array_map(fn($i) => $i->getName(), $usersTable->getIndexes());
        if (in_array('UNIQ_1483A5E99F75D7B0', $indexNames, true)) {
            $this->addSql('DROP INDEX UNIQ_1483A5E99F75D7B0 ON users');
        }

        $colNames = array_map(fn($c) => $c->getName(), $usersTable->getColumns());
        $drops = array_filter(
            ['affiliate_code', 'affiliate_commission_rate', 'referred_by_id'],
            fn($col) => in_array($col, $colNames, true)
        );
        if (!empty($drops)) {
            $this->addSql('ALTER TABLE users DROP ' . implode(', DROP ', $drops));
        }
    }
}
