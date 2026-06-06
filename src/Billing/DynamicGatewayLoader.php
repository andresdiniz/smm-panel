<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\ProviderCredential;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Carrega gateways de pagamento dinamicamente a partir do banco.
 * Permite trocar credenciais sem redeploy.
 */
final class DynamicGatewayLoader
{
    public function __construct(
        private readonly ProviderCredentialRepository $repo,
        private readonly EntityManagerInterface       $em,
        private readonly HttpClientInterface          $http,
    ) {}

    public function load(string $slug): PaymentGatewayInterface
    {
        $cred = $this->repo->findBySlug(ProviderCredential::TYPE_PAYMENT, $slug);
        if (!$cred) {
            throw new \RuntimeException("Credencial de gateway não encontrada no banco: {$slug}");
        }

        return match ($slug) {
            'asaas'       => new AsaasGateway($this->em, $this->http, $cred->getApiKey(), $cred->getBaseUrl(), $cred->getSecretToken() ?? ''),
            'mercadopago' => new MercadoPagoGateway($this->em, $this->http, $cred->getApiKey(), $cred->getBaseUrl(), $cred->getSecretToken() ?? ''),
            'pagbank'     => new PagBankGateway($this->em, $this->http, $cred->getApiKey(), $cred->getBaseUrl(), $cred->getSecretToken() ?? ''),
            default       => throw new \InvalidArgumentException("Gateway desconhecido: {$slug}"),
        };
    }

    public function buildRegistry(): GatewayRegistry
    {
        $credentials = $this->repo->findAllActiveByType(ProviderCredential::TYPE_PAYMENT);
        $gateways    = [];

        foreach ($credentials as $slug => $cred) {
            try {
                $gateways[$slug] = $this->load($slug);
            } catch (\InvalidArgumentException) {
                // slug desconhecido, ignora
            }
        }

        return new GatewayRegistry(new \ArrayObject($gateways));
    }
}
