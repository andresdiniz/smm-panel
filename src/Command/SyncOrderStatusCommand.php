<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sincroniza status de pedidos ativos consultando o provider diretamente (sem fila).
 * Ideal para execução via cron ou debug manual.
 *
 * Uso:
 *   php bin/console app:sync-order-status
 *   php bin/console app:sync-order-status --order=19
 *   php bin/console app:sync-order-status --limit=200
 *
 * Crontab (a cada 2 min):
 *   *\/2 * * * * php /var/www/bin/console app:sync-order-status >> /var/log/smm_sync.log 2>&1
 */
#[AsCommand(
    name: 'app:sync-order-status',
    description: 'Sincroniza status de pedidos ativos com os providers SMM (direto, sem fila).'
)]
final class SyncOrderStatusCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly LoggerInterface        $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Máximo de pedidos a processar por execução',
                100
            )
            ->addOption(
                'order',
                null,
                InputOption::VALUE_OPTIONAL,
                'ID de pedido específico para sincronizar',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = (int) $input->getOption('limit');
        $orderId = $input->getOption('order');

        // ── Modo pedido único ────────────────────────────────────────────
        if ($orderId !== null) {
            $order = $this->em->find(Order::class, (int) $orderId);
            if (!$order) {
                $io->error("Pedido #{$orderId} não encontrado.");
                return Command::FAILURE;
            }
            $orders = [$order];
        } else {
            // ── Modo lote ────────────────────────────────────────────────
            $orders = $this->em->createQueryBuilder()
                ->select('o')
                ->from(Order::class, 'o')
                ->join('o.service', 's')
                ->where('o.status IN (:statuses)')
                ->andWhere('o.externalOrderId IS NOT NULL')
                ->andWhere('s.providerSlug IS NOT NULL')
                ->setParameter('statuses', [
                    Order::STATUS_PROCESSING,
                    Order::STATUS_IN_PROGRESS,
                    Order::STATUS_PARTIAL,
                ])
                ->setMaxResults($limit)
                ->orderBy('o.createdAt', 'ASC')
                ->getQuery()
                ->getResult();
        }

        if (empty($orders)) {
            $io->info('Nenhum pedido ativo para sincronizar.');
            return Command::SUCCESS;
        }

        $io->progressStart(count($orders));
        $updated = 0;
        $errors  = 0;

        foreach ($orders as $order) {
            /** @var Order $order */
            $slug = $order->getService()->getProviderSlug();

            if (!$this->registry->has($slug)) {
                $io->warning("Provider '{$slug}' não encontrado no registry.");
                $io->progressAdvance();
                continue;
            }

            try {
                $provider  = $this->registry->get($slug);
                $status    = $provider->getOrderStatus($order->getExternalOrderId());
                $newStatus = $this->mapProviderStatus($status['status']);

                if (isset($status['start_count'])) {
                    $order->setStartCount((int) $status['start_count']);
                }
                if (isset($status['remains'])) {
                    $order->setRemains((int) $status['remains']);
                }

                if ($newStatus !== $order->getStatus()) {
                    $this->logger->info('SyncOrderStatusCommand: status atualizado.', [
                        'order_id'   => $order->getId(),
                        'old_status' => $order->getStatus(),
                        'new_status' => $newStatus,
                    ]);
                    $order->setStatus($newStatus);
                    ++$updated;
                }

                $io->writeln(sprintf(
                    '  Order #%d → provider: <info>%s</info> | status: <comment>%s</comment>',
                    $order->getId(),
                    $status['status'],
                    $newStatus
                ), OutputInterface::VERBOSITY_VERBOSE);

            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('SyncOrderStatusCommand: erro ao sincronizar.', [
                    'order_id' => $order->getId(),
                    'error'    => $e->getMessage(),
                ]);
                $io->writeln("  <error>Order #{$order->getId()}: {$e->getMessage()}</error>");
            }

            $io->progressAdvance();
        }

        $this->em->flush();
        $io->progressFinish();
        $io->success(sprintf(
            'Processados: %d | Atualizados: %d | Erros: %d',
            count($orders),
            $updated,
            $errors
        ));

        return Command::SUCCESS;
    }

    private function mapProviderStatus(string $raw): string
    {
        return match (strtolower($raw)) {
            'pending'                => Order::STATUS_PENDING,
            'processing'             => Order::STATUS_PROCESSING,
            'in progress',
            'inprogress'             => Order::STATUS_IN_PROGRESS,
            'completed'              => Order::STATUS_COMPLETED,
            'partial'                => Order::STATUS_PARTIAL,
            'cancelled', 'canceled'  => Order::STATUS_CANCELLED,
            'refunded'               => Order::STATUS_REFUNDED,
            default                  => Order::STATUS_PROCESSING,
        };
    }
}
