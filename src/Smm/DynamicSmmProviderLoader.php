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
 *
 * Os slugs são normalizados para strtolower(trim()) antes de serem
 * indexados no registry, mantendo consistência com os slugs usados
 * nas entidades Service.providerSlug.
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
        foreach ($credentials as $cred) {
            $slug = strtolower(trim($cred->getSlug()));
            $providers[$slug] = new GenericSmmProvider(
                $this->http,
                $slug,
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
        $normalized = strtolower(trim($slug));
        $cred = $this->repo->findBySlug(ProviderCredential::TYPE_SMM, $normalized);
        if (!$cred) {
            // Tenta com o slug original caso o banco armazene com case diferente
            $cred = $this->repo->findBySlug(ProviderCredential::TYPE_SMM, $slug);
        }
        if (!$cred) {
            return null;
        }

        return new GenericSmmProvider(
            $this->http,
            $normalized,
            $cred->getBaseUrl(),
            $cred->getApiKey(),
            $this->logger,
        );
    }
}
