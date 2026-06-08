<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Insere credencial payment_gateway/asaas no banco caso ainda não exista.
 *
 * ANTES de rodar esta migration, defina no servidor:
 *   ASAAS_API_KEY  = sua chave da API Asaas (começa com $aact_...)
 *   ASAAS_BASE_URL = https://sandbox.asaas.com/api/v3  (sandbox)
 *                    https://api.asaas.com/v3           (produção)
 *
 * A migration usa INSERT IGNORE para ser idempotente: rodar duas vezes
 * não gera erro nem duplicata.
 */
final class Version20260608_SeedAsaas extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed: credencial payment_gateway/asaas via env vars';
    }

    public function up(Schema $schema): void
    {
        $apiKey  = $_ENV['ASAAS_API_KEY']  ?? 'CONFIGURE_ASAAS_API_KEY_NO_ENV';
        $baseUrl = $_ENV['ASAAS_BASE_URL'] ?? 'https://sandbox.asaas.com/api/v3';

        $this->addSql(<<<SQL
            INSERT IGNORE INTO provider_credentials
                (type, slug, base_url, api_key, secret_token, active, created_at)
            VALUES
                ('payment_gateway', 'asaas', :base_url, :api_key, NULL, 1, NOW())
            SQL,
            ['base_url' => $baseUrl, 'api_key' => $apiKey],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM provider_credentials WHERE type = 'payment_gateway' AND slug = 'asaas'"
        );
    }
}
