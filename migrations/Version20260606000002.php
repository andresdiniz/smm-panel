<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomeia tabela contact -> contacts para bater com a entidade';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact RENAME TO contacts');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts RENAME TO contact');
    }
}
