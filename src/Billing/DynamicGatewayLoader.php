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
        private readonly string                      $asaasEnvironment = 'sandbox',
    ) {}

    public function load(string $slug): PaymentGatewayInterface
    {
        $slug = strtolower(trim($slug));
        $cred = $this->repo->findBySlug(ProviderCredential::TYPE_PAYMENT, $slug);
        if (!$cred) {
            throw new \RuntimeException("Credencial de gateway não encontrada no banco: {$slug}");
        }

        return $this->createGateway($slug, $cred);
    }

    private function createGateway(string $slug, ProviderCredential $cred): PaymentGatewayInterface
    {
        return match ($slug) {
            'asaas'       => new AsaasGateway(
                $this->em,
                $this->http,
                $cred->getApiKey(),
                $this->asaasEnvironment,
                $cred->getSecretToken() ?? '',
            ),
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
                $gateways[$slug] = $this->createGateway($slug, $cred);
            } catch (\InvalidArgumentException) {
                // Gateways não suportados não entram no registry.
            }
        }

        return new GatewayRegistry(new \ArrayObject($gateways));
    }
}
