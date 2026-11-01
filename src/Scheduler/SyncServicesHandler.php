<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\CronLog;
use App\Entity\Service;
use App\Repository\ServiceRepository;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Processa o SyncServicesMessage disparado pelo Scheduler.
 * Atualiza minQty e maxQty de todos os serviços ativos
 * com os valores reais retornados pela API de cada provider.
 */
#[AsMessageHandler]
final class SyncServicesHandler
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAYS_MS = [30_000, 120_000, 300_000];

    public function __construct(
        private readonly ServiceRepository     $serviceRepository,
        private readonly SmmProviderRegistry   $providerRegistry,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly MessageBusInterface    $bus,
    ) {}

    public function __invoke(SyncServicesMessage $message): void
    {
        $startedAt = hrtime(true);
        $cronLog = (new CronLog('smm:services:sync'))->setContext([
            'provider_filter' => $message->providerSlug,
            'attempt' => $message->attempt,
        ]);
        $servicesProcessed = 0;
        $totalUpdated = 0;
        $providerErrors = [];
        $providerErrorDetails = [];
        $unmatchedServices = 0;

        $this->logger->info('[SyncServices] Iniciando sincronização de serviços com providers.', [
            'provider' => $message->providerSlug,
            'attempt' => $message->attempt,
        ]);

        try {
            /** @var Service[] $services */
            $services = $this->serviceRepository->findBy(['active' => true]);
            $services = array_filter(
                $services,
                fn(Service $s) => $s->getExternalServiceId() !== null
                    && $s->getProviderSlug() !== null
                    && ($message->providerSlug === null || $s->getProviderSlug() === strtolower(trim($message->providerSlug)))
            );
            $servicesProcessed = count($services);

            if ($services === []) {
                $this->logger->info('[SyncServices] Nenhum serviço elegível encontrado.');
            } else {
                $this->syncServices($services, $totalUpdated, $providerErrors, $providerErrorDetails, $unmatchedServices);
            }

            if ($totalUpdated > 0) {
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $providerErrors[] = $message->providerSlug ?? '*';
            $providerErrorDetails[$message->providerSlug ?? '*'] = $e->getMessage();
            $this->logger->error('[SyncServices] Falha durante sincronização.', [
                'provider' => $message->providerSlug,
                'attempt' => $message->attempt,
                'error' => $e->getMessage(),
            ]);
        }

        $providerErrors = array_values(array_unique($providerErrors));
        $success = $providerErrors === [];
        $errorMessage = $success
            ? null
            : sprintf('Falha nos providers: %s.', implode(', ', $providerErrors));

        $cronLog
            ->setItemsProcessed($servicesProcessed)
            ->setContext([
                'provider_filter' => $message->providerSlug,
                'attempt' => $message->attempt,
                'updated' => $totalUpdated,
                'unmatched_services' => $unmatchedServices,
                'provider_errors' => $providerErrors,
                'provider_error_details' => $providerErrorDetails,
            ])
            ->finish($success, $errorMessage);
        $this->em->persist($cronLog);
        $this->em->flush();

        if (!$success && $message->attempt < self::MAX_ATTEMPTS) {
            $delay = self::RETRY_DELAYS_MS[$message->attempt];
            foreach ($providerErrors as $slug) {
                $this->bus->dispatch(
                    new SyncServicesMessage($slug === '*' ? null : $slug, $message->attempt + 1),
                    [new DelayStamp($delay)],
                );
            }

            $this->logger->warning('[SyncServices] Falha registrada; nova tentativa enfileirada.', [
                'providers' => $providerErrors,
                'next_attempt' => $message->attempt + 1,
                'delay_ms' => $delay,
            ]);
        } elseif (!$success) {
            $this->logger->error('[SyncServices] Tentativas esgotadas; consulte CronLog no admin.', [
                'providers' => $providerErrors,
                'attempt' => $message->attempt,
            ]);
        }

        $this->logger->info('[SyncServices] Sincronização concluída.', [
            'processed' => $servicesProcessed,
            'updated' => $totalUpdated,
            'provider_errors' => $providerErrors,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }

    /**
     * @param Service[] $services
     * @param string[] $providerErrors
     * @param array<string, string> $providerErrorDetails
     */
    private function syncServices(
        array $services,
        int &$totalUpdated,
        array &$providerErrors,
        array &$providerErrorDetails,
        int &$unmatchedServices,
    ): void
    {
        $byProvider = [];
        foreach ($services as $service) {
            $byProvider[$service->getProviderSlug()][] = $service;
        }

        foreach ($byProvider as $slug => $providerServices) {
            try {
                $remoteServices = $this->providerRegistry->get($slug)->getServices();
            } catch (\Throwable $e) {
                $this->recordProviderError($slug, $e, $providerErrors, $providerErrorDetails);
                continue;
            }

            $remoteIndex = [];
            foreach ($remoteServices as $remoteService) {
                if (!is_array($remoteService) || !isset($remoteService['service'], $remoteService['min'], $remoteService['max'])) {
                    $this->logger->error('[SyncServices] Resposta inválida ao listar serviços do provider.', [
                        'provider' => $slug,
                    ]);
                    $providerErrors[] = $slug;
                    $providerErrorDetails[$slug] = 'Resposta inválida ao listar serviços do provider.';
                    continue 2;
                }
                $remoteIndex[(string) $remoteService['service']] = $remoteService;
            }

            foreach ($providerServices as $service) {
                $extId = (string) $service->getExternalServiceId();

                if (!isset($remoteIndex[$extId])) {
                    ++$unmatchedServices;
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
    }

    /** @param string[] $providerErrors */
    private function recordProviderError(
        string $slug,
        \Throwable $exception,
        array &$providerErrors,
        array &$providerErrorDetails,
    ): void {
        $this->logger->error('[SyncServices] Falha ao buscar serviços do provider.', [
            'provider' => $slug,
            'error' => $exception->getMessage(),
        ]);
        $providerErrors[] = $slug;
        $providerErrorDetails[$slug] = $exception->getMessage();
    }
}
