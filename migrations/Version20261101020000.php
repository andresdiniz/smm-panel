<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds composite indexes for user transaction history queries';
    }

    public function up(Schema $schema): void
    {
        $indexes = [
            'orders' => ['idx_orders_user_created_id' => '(user_id, created_at, id)'],
            'payments' => ['idx_payments_user_created_id' => '(user_id, created_at, id)'],
            'wallet_transactions' => [
                'idx_wallet_tx_wallet_created_id' => '(wallet_id, created_at, id)',
                'idx_wallet_tx_created_type' => '(created_at, type)',
            ],
        ];

        foreach ($indexes as $tableName => $tableIndexes) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);
            foreach ($tableIndexes as $indexName => $columns) {
                if (!$table->hasIndex($indexName)) {
                    $this->addSql(sprintf('CREATE INDEX %s ON %s %s', $indexName, $tableName, $columns));
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $indexes = [
            'orders' => 'idx_orders_user_created_id',
            'payments' => 'idx_payments_user_created_id',
        ];

        foreach ($indexes as $tableName => $indexName) {
            if ($schema->hasTable($tableName) && $schema->getTable($tableName)->hasIndex($indexName)) {
                $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $tableName));
            }
        }

        foreach (['idx_wallet_tx_created_type', 'idx_wallet_tx_wallet_created_id'] as $indexName) {
            if ($schema->hasTable('wallet_transactions')
                && $schema->getTable('wallet_transactions')->hasIndex($indexName)
            ) {
                $this->addSql(sprintf('DROP INDEX %s ON wallet_transactions', $indexName));
            }
        }
    }
}
