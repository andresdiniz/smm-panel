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

        $slug = $service->getProviderSlug();
        $externalId = $service->getExternalServiceId();

        if (!$slug || !$externalId || !$this->registry->has($slug)) {
            throw new \InvalidArgumentException('Serviço sem provider ou ID externo válido.');
        }

        $provider = $this->registry->get($slug);
        $services = $provider->listServices();

        $providerService = null;
        foreach ($services as $item) {
            if ((string) ($item['id'] ?? '') === (string) $externalId) {
                $providerService = $item;
                break;
            }
        }

        if (!$providerService || !isset($providerService['rate'])) {
            throw new \RuntimeException("Serviço externo #{$externalId} não encontrado no provider {$slug}.");
        }

        $providerRate = (float) $providerService['rate'];
        $newPriceCents = (int) ceil($providerRate * (1 + ($markupPercent / 100)) * 100);
        $oldPriceCents = $service->getPricePerThousandCents();

        $service->setPricePerThousandCents($newPriceCents);
        $this->em->flush();

        $this->logger->info('ServicePriceSyncService: preço atualizado.', [
            'service_id' => $serviceId,
            'provider' => $slug,
            'old_price_cents' => $oldPriceCents,
            'new_price_cents' => $newPriceCents,
            'provider_rate' => $providerRate,
            'markup_percent' => $markupPercent,
        ]);

        return [
            'service_id' => $serviceId,
            'provider' => $slug,
            'external_service_id' => $externalId,
            'provider_rate' => $providerRate,
            'markup_percent' => $markupPercent,
            'old_price_cents' => $oldPriceCents,
            'new_price_cents' => $newPriceCents,
            'changed' => $oldPriceCents !== $newPriceCents,
        ];
    }
}
