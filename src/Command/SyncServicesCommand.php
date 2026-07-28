<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Service;
use App\Repository\ServiceRepository;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sincroniza minQty, maxQty e pricePerThousandCents dos serviços
 * com os valores reais retornados pela API de cada provider.
 *
 * Uso:
 *   php bin/console app:sync-services
 *   php bin/console app:sync-services --provider=smmofficial
 *   php bin/console app:sync-services --dry-run
 */
#[AsCommand(
    name: 'app:sync-services',
    description: 'Sincroniza minQty/maxQty/rate dos serviços com a API dos providers.',
)]
class SyncServicesCommand extends Command
{
    public function __construct(
        private readonly ServiceRepository    $serviceRepository,
        private readonly SmmProviderRegistry  $providerRegistry,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider', 'p', InputOption::VALUE_OPTIONAL, 'Slug do provider a sincronizar (omitir = todos)')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Mostra o que seria alterado sem salvar no banco')
            ->addOption('sync-price', null, InputOption::VALUE_NONE,   'Também atualiza pricePerThousandCents (desabilitado por padrão)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $syncPrice = (bool) $input->getOption('sync-price');
        $filterSlug = $input->getOption('provider') ? strtolower(trim((string) $input->getOption('provider'))) : null;

        $io->title('Sync de serviços com providers SMM');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN: nenhuma alteração será salva.');
        }

        // Busca todos os serviços ativos que têm externalServiceId + providerSlug
        /** @var Service[] $services */
        $services = $this->serviceRepository->findBy(['active' => true]);
        $services = array_filter($services, fn(Service $s) =>
            $s->getExternalServiceId() !== null && $s->getProviderSlug() !== null
        );

        if ($filterSlug !== null) {
            $services = array_filter($services, fn(Service $s) => $s->getProviderSlug() === $filterSlug);
        }

        if (empty($services)) {
            $io->warning('Nenhum serviço encontrado com providerSlug + externalServiceId preenchidos.');
            return Command::SUCCESS;
        }

        // Agrupa serviços por provider slug para fazer uma única chamada de API por provider
        $byProvider = [];
        foreach ($services as $service) {
            $byProvider[$service->getProviderSlug()][] = $service;
        }

        $totalUpdated  = 0;
        $totalSkipped  = 0;
        $totalErrors   = 0;

        foreach ($byProvider as $slug => $providerServices) {
            $io->section(sprintf('Provider: %s (%d serviços)', $slug, count($providerServices)));

            try {
                $provider = $this->providerRegistry->get($slug);
            } catch (\Throwable $e) {
                $io->error(sprintf('[%s] Provider não encontrado no registry: %s', $slug, $e->getMessage()));
                $totalErrors += count($providerServices);
                continue;
            }

            // Busca lista de serviços do provider (uma única chamada por provider)
            try {
                $remoteServices = $provider->getServices();
            } catch (\Throwable $e) {
                $io->error(sprintf('[%s] Falha ao buscar serviços da API: %s', $slug, $e->getMessage()));
                $totalErrors += count($providerServices);
                continue;
            }

            // Indexa a resposta por service ID para lookup O(1)
            $remoteIndex = [];
            foreach ($remoteServices as $rs) {
                $remoteIndex[(string) $rs['service']] = $rs;
            }

            $io->writeln(sprintf('  → %d serviços retornados pela API do provider.', count($remoteIndex)));

            $rows = [];

            foreach ($providerServices as $service) {
                $extId = (string) $service->getExternalServiceId();

                if (!isset($remoteIndex[$extId])) {
                    $io->writeln(sprintf(
                        '  <comment>[SKIP]</comment> Serviço #%d (ext %s) não encontrado na API.',
                        $service->getId(),
                        $extId
                    ));
                    ++$totalSkipped;
                    continue;
                }

                $remote = $remoteIndex[$extId];

                $newMin = max(1, (int) $remote['min']);
                $newMax = max(1, (int) $remote['max']);
                $oldMin = $service->getMinQty();
                $oldMax = $service->getMaxQty();

                $changed = false;

                if ($oldMin !== $newMin || $oldMax !== $newMax) {
                    $changed = true;
                    if (!$dryRun) {
                        $service->setMinQty($newMin);
                        $service->setMaxQty($newMax);
                    }
                }

                // Atualiza preço se --sync-price foi passado
                $oldPriceCents = $service->getPricePerThousandCents();
                $newPriceCents = $oldPriceCents;
                if ($syncPrice) {
                    // rate da API é em USD/1000. Armazenamos em centavos (rate * 100).
                    $newPriceCents = (int) round((float) $remote['rate'] * 100);
                    if ($oldPriceCents !== $newPriceCents) {
                        $changed = true;
                        if (!$dryRun) {
                            $service->setPricePerThousandCents($newPriceCents);
                        }
                    }
                }

                $status = $changed ? '<info>UPDATE</info>' : '<fg=gray>SAME</>';
                if ($changed) {
                    ++$totalUpdated;
                }

                $rows[] = [
                    $service->getId(),
                    substr($service->getName(), 0, 40),
                    $extId,
                    $oldMin . ' → ' . ($changed && $oldMin !== $newMin ? "<info>$newMin</info>" : $oldMin),
                    $oldMax . ' → ' . ($changed && $oldMax !== $newMax ? "<info>$newMax</info>" : $oldMax),
                    $syncPrice ? ($oldPriceCents . ' → ' . ($changed && $oldPriceCents !== $newPriceCents ? "<info>$newPriceCents</info>" : $oldPriceCents)) : '-',
                    $status,
                ];
            }

            if (!empty($rows)) {
                $headers = ['ID', 'Nome', 'Ext ID', 'minQty', 'maxQty', 'Price/1k¢', 'Status'];
                $io->table($headers, $rows);
            }
        }

        if (!$dryRun && $totalUpdated > 0) {
            $this->em->flush();
            $io->success(sprintf('%d serviço(s) atualizado(s) no banco.', $totalUpdated));
        } elseif ($dryRun) {
            $io->note(sprintf('DRY-RUN: %d serviço(s) seriam atualizados.', $totalUpdated));
        } else {
            $io->success('Todos os serviços já estavam sincronizados.');
        }

        if ($totalSkipped > 0) {
            $io->warning(sprintf('%d serviço(s) ignorados (não encontrados na API do provider).', $totalSkipped));
        }

        if ($totalErrors > 0) {
            $io->error(sprintf('%d serviço(s) com erro (ver mensagens acima).', $totalErrors));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
