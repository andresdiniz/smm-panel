<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Service;
use App\Repository\ServiceRepository;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processa o SyncServicesMessage disparado pelo Scheduler.
 * Atualiza minQty e maxQty de todos os serviços ativos
 * com os valores reais retornados pela API de cada provider.
 */
#[AsMessageHandler]
final class SyncServicesHandler
{
    public function __construct(
        private readonly ServiceRepository     $serviceRepository,
        private readonly SmmProviderRegistry   $providerRegistry,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(SyncServicesMessage $message): void
    {
        $this->logger->info('[SyncServices] Iniciando sincronização de serviços com providers.');

        /** @var Service[] $services */
        $services = $this->serviceRepository->findBy(['active' => true]);
        $services = array_filter(
            $services,
            fn(Service $s) => $s->getExternalServiceId() !== null && $s->getProviderSlug() !== null
        );

        if (empty($services)) {
            $this->logger->info('[SyncServices] Nenhum serviço elegível encontrado.');
            return;
        }

        // Agrupa por provider para uma chamada de API por provider
        $byProvider = [];
        foreach ($services as $service) {
            $byProvider[$service->getProviderSlug()][] = $service;
        }

        $totalUpdated = 0;

        foreach ($byProvider as $slug => $providerServices) {
            try {
                $provider = $this->providerRegistry->get($slug);
                $remoteServices = $provider->getServices();
            } catch (\Throwable $e) {
                $this->logger->error('[SyncServices] Falha ao buscar serviços do provider', [
                    'provider' => $slug,
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }

            // Indexa por service ID para lookup O(1)
            $remoteIndex = [];
            foreach ($remoteServices as $rs) {
                $remoteIndex[(string) $rs['service']] = $rs;
            }

            foreach ($providerServices as $service) {
                $extId = (string) $service->getExternalServiceId();

                if (!isset($remoteIndex[$extId])) {
                    $this->logger->warning('[SyncServices] Serviço não encontrado na API do provider', [
                        'service_id'  => $service->getId(),
                        'external_id' => $extId,
                        'provider'    => $slug,
                    ]);
                    continue;
                }

                $remote = $remoteIndex[$extId];
                $newMin = max(1, (int) $remote['min']);
                $newMax = max(1, (int) $remote['max']);

                if ($service->getMinQty() !== $newMin || $service->getMaxQty() !== $newMax) {
                    $this->logger->info('[SyncServices] Atualizando serviço', [
                        'service_id'  => $service->getId(),
                        'external_id' => $extId,
                        'provider'    => $slug,
                        'old_min'     => $service->getMinQty(),
                        'new_min'     => $newMin,
                        'old_max'     => $service->getMaxQty(),
                        'new_max'     => $newMax,
                    ]);

                    $service->setMinQty($newMin);
                    $service->setMaxQty($newMax);
                    ++$totalUpdated;
                }
            }
        }

        if ($totalUpdated > 0) {
            $this->em->flush();
        }

        $this->logger->info('[SyncServices] Sincronização concluída.', [
            'updated' => $totalUpdated,
        ]);
    }
}
