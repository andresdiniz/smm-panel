<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProviderCredential;
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

    public function getProviderConfig(Service $service): array
    {
        $slug = $service->getProviderSlug();
        $credential = $slug
            ? $this->em->getRepository(ProviderCredential::class)->findOneBy([
                'type' => ProviderCredential::TYPE_SMM,
                'slug' => $slug,
            ])
            : null;

        return [
            'currency' => $credential?->getCurrency() ?? 'BRL',
            'exchange_rate' => $credential?->getExchangeRate() ?? 1.0,
            'default_profit_percent' => $credential?->getDefaultProfitPercent() ?? 0.0,
        ];
    }

    public function getProviderPrice(Service $service): array
    {
        $providerService = $this->findProviderService($service);
        $config = $this->getProviderConfig($service);

        $providerRate = (float) $providerService['rate'];
        $exchangeRate = (float) $config['exchange_rate'];
        $costCents = (int) ceil($providerRate * $exchangeRate * 100);
        $profitPercent = $this->resolveProfitPercent($service, $config);

        return [
            'service_id' => (int) $service->getId(),
            'provider' => (string) $service->getProviderSlug(),
            'external_service_id' => (string) $service->getExternalServiceId(),
            'provider_currency' => $config['currency'],
            'exchange_rate' => $exchangeRate,
            'provider_rate' => $providerRate,
            'cost_brl_cents' => $costCents,
            'current_price_cents' => $service->getPricePerThousandCents(),
            'profit_percent' => $profitPercent,
            'suggested_price_cents' => $this->calculatePriceCents($costCents, $profitPercent),
        ];
    }

    public function syncOne(int $serviceId, ?float $profitOverride = null): array
    {
        $service = $this->em->find(Service::class, $serviceId);

        if (!$service) {
            throw new \InvalidArgumentException("Serviço #{$serviceId} não encontrado.");
        }

        $providerService = $this->findProviderService($service);
        $config = $this->getProviderConfig($service);
        $providerRate = (float) $providerService['rate'];
        $exchangeRate = (float) $config['exchange_rate'];
        $costCents = (int) ceil($providerRate * $exchangeRate * 100);
        $profitPercent = $profitOverride ?? $this->resolveProfitPercent($service, $config);
        $newPriceCents = $this->calculatePriceCents($costCents, $profitPercent);
        $oldPriceCents = $service->getPricePerThousandCents();

        $service->setPricePerThousandCents($newPriceCents);
        $service->setProfitPercent($profitPercent);
        $this->em->flush();

        $this->logger->info('ServicePriceSyncService: preço atualizado com câmbio.', [
            'service_id' => $serviceId,
            'provider' => $service->getProviderSlug(),
            'currency' => $config['currency'],
            'exchange_rate' => $exchangeRate,
            'provider_rate' => $providerRate,
            'profit_percent' => $profitPercent,
            'old_price_cents' => $oldPriceCents,
            'new_price_cents' => $newPriceCents,
        ]);

        return [
            'service_id' => $serviceId,
            'provider' => (string) $service->getProviderSlug(),
            'provider_currency' => $config['currency'],
            'exchange_rate' => $exchangeRate,
            'provider_rate' => $providerRate,
            'cost_brl_cents' => $costCents,
            'profit_percent' => $profitPercent,
            'old_price_cents' => $oldPriceCents,
            'new_price_cents' => $newPriceCents,
            'changed' => $oldPriceCents !== $newPriceCents,
        ];
    }

    public function recalcProfitFromPrice(Service $service): void
    {
        $providerService = $this->findProviderService($service);
        $config = $this->getProviderConfig($service);
        $providerRate = (float) $providerService['rate'];
        $exchangeRate = (float) $config['exchange_rate'];
        $costCents = (int) ceil($providerRate * $exchangeRate * 100);

        if ($costCents <= 0) {
            return;
        }

        $priceCents = $service->getPricePerThousandCents();
        $profitPercent = (($priceCents / $costCents) - 1) * 100;
        $service->setProfitPercent(round($profitPercent, 3));
    }

    private function resolveProfitPercent(Service $service, array $config): float
    {
        return $service->getProfitPercent()
            ?? $config['default_profit_percent'];
    }

    private function calculatePriceCents(int $costCents, float $profitPercent): int
    {
        return (int) ceil($costCents * (1 + ($profitPercent / 100)));
    }

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
