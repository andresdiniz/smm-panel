<?php

declare(strict_types=1);

namespace App\Smm;

use App\Entity\ProviderCredential;
use App\Repository\ProviderCredentialRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Carrega providers SMM dinamicamente a partir das credenciais no banco.
 * Substitui a definição estática em services.yaml.
 */
final class DynamicSmmProviderLoader
{
    public function __construct(
        private readonly ProviderCredentialRepository $repo,
        private readonly HttpClientInterface          $http,
        private readonly LoggerInterface              $logger,
    ) {}

    /**
     * Constrói e retorna um SmmProviderRegistry populado a partir do banco.
     * Deve ser chamado uma vez por request/processo.
     */
    public function buildRegistry(): SmmProviderRegistry
    {
        $credentials = $this->repo->findAllActiveByType(ProviderCredential::TYPE_SMM);

        $providers = [];
        foreach ($credentials as $slug => $cred) {
            $providers[$slug] = new GenericSmmProvider(
                $this->http,
                $cred->getSlug(),
                $cred->getBaseUrl(),
                $cred->getApiKey(),
                $this->logger,
            );
        }

        return new SmmProviderRegistry(new \ArrayObject($providers));
    }

    /**
     * Retorna um único provider por slug, sem carregar todos.
     */
    public function loadBySlug(string $slug): ?SmmProviderInterface
    {
        $cred = $this->repo->findBySlug(ProviderCredential::TYPE_SMM, $slug);
        if (!$cred) {
            return null;
        }

        return new GenericSmmProvider(
            $this->http,
            $cred->getSlug(),
            $cred->getBaseUrl(),
            $cred->getApiKey(),
            $this->logger,
        );
    }
}
