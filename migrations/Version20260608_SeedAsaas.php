<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Esta migration foi esvaziada intencionalmente.
 *
 * As credenciais do gateway de pagamento (Asaas, MercadoPago, PagBank)
 * são cadastradas EXCLUSIVAMENTE pelo painel administrativo em:
 *   /admin -> Credenciais de Provedores
 *
 * Não é necessário definir ASAAS_API_KEY nem ASAAS_BASE_URL no .env.
 * O sistema lê tudo do banco de dados.
 */
final class Version20260608_SeedAsaas extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: credenciais de gateway gerenciadas pelo painel admin (banco de dados)';
    }

    public function up(Schema $schema): void
    {
        // Intencionalmente vazio.
        // Cadastre a credencial em: Admin > Credenciais de Provedores
    }

    public function down(Schema $schema): void
    {
        // Nada a reverter.
    }
}
