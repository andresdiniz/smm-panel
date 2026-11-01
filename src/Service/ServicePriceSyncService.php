<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Service;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ServicePriceSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Consulta o preço atual do serviço no provider sem alterar o banco.
     *
     * @return array{
     *     service_id: int,
     *     provider: string,
     *     external_service_id: string,
     *     provider_rate: float,
     *     provider_price_cents: int,
     *     current_price_cents: int,
     *     suggested_price_cents: int,
     *     difference_cents: int
     * }
     */
    public function getProviderPrice(Service $service): array
    {
        $providerService = $this->findProviderService($service);
        $providerRate = (float) $providerService['rate'];
        $providerPriceCents = (int) ceil($providerRate * 100);
        $currentPriceCents = $service->getPricePerThousandCents();

        return [
            'service_id' => (int) $service->getId(),
            'provider' => (string) $service->getProviderSlug(),
            'external_service_id' => (string) $service->getExternalServiceId(),
            'provider_rate' => $providerRate,
            'provider_price_cents' => $providerPriceCents,
            'current_price_cents' => $currentPriceCents,
            'suggested_price_cents' => $providerPriceCents,
            'difference_cents' => $providerPriceCents - $currentPriceCents,
        ];
    }

    /**
     * Atualiza o preço local de um serviço com base no rate atual do provider.
     *
     * @return array{
     *     service_id: int,
     *     provider: string,
     *     external_service_id: string,
     *     provider_rate: float,
     *     markup_percent: float,
     *     old_price_cents: int,
     *     new_price_cents: int,
     *     changed: bool
     * }
     */
    public function syncOne(int $serviceId, float $markupPercent = 0.0): array
    {
        $service = $this->em->find(Service::class, $serviceId);

        if (!$service) {
            throw new \InvalidArgumentException("Serviço #{$serviceId} não encontrado.");
        }

        $providerService = $this->findProviderService($service);
        $providerRate = (float) $providerService['rate'];
        $newPriceCents = (int) ceil($providerRate * (1 + ($markupPercent / 100)) * 100);
        $oldPriceCents = $service->getPricePerThousandCents();

        $service->setPricePerThousandCents($newPriceCents);
        $this->em->flush();

        $this->logger->info('ServicePriceSyncService: preço atualizado.', [
            'service_id' => $serviceId,
            'provider' => $service->getProviderSlug(),
            'old_price_cents' => $oldPriceCents,
            'new_price_cents' => $newPriceCents,
            'provider_rate' => $providerRate,
            'markup_percent' => $markupPercent,
        ]);

        return [
            'service_id' => $serviceId,
            'provider' => (string) $service->getProviderSlug(),
            'external_service_id' => (string) $service->getExternalServiceId(),
            'provider_rate' => $providerRate,
            'markup_percent' => $markupPercent,
            'old_price_cents' => $oldPriceCents,
            'new_price_cents' => $newPriceCents,
            'changed' => $oldPriceCents !== $newPriceCents,
        ];
    }

    /**
     * @return array{id: string|int, rate: float|string}
     */
    private function findProviderService(Service $service): array
    {
        $slug = $service->getProviderSlug();
        $externalId = $service->getExternalServiceId();

        if (!$slug || !$externalId || !$this->registry->has($slug)) {
            throw new \InvalidArgumentException('Serviço sem provider ou ID externo válido.');
        }

        $provider = $this->registry->get($slug);
        $services = $provider->listServices();

        foreach ($services as $item) {
            if ((string) ($item['id'] ?? '') === (string) $externalId) {
                return $item;
            }
        }

        throw new \RuntimeException("Serviço externo #{$externalId} não encontrado no provider {$slug}.");
    }
}
