<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031182046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexa o ID externo de pagamentos para acelerar webhooks e conciliação';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$schema->hasTable('payments'), 'A tabela payments deve existir antes desta migration.');

        if (!$schema->getTable('payments')->hasIndex('IDX_payments_external_id')) {
            $this->addSql('CREATE INDEX IDX_payments_external_id ON payments (external_id)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('payments') && $schema->getTable('payments')->hasIndex('IDX_payments_external_id')) {
            $this->addSql('DROP INDEX IDX_payments_external_id ON payments');
        }
    }
}
