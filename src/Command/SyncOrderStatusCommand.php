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
 * Sincroniza status de pedidos ativos com os providers SMM externos.
 *
 * Uso manual:
 *   php bin/console app:sync-order-status
 *   php bin/console app:sync-order-status --limit=200
 *
 * Agendar via Symfony Scheduler (src/Scheduler/) ou crontab:
 *   * * * * * /usr/bin/php /var/www/bin/console app:sync-order-status >> /var/log/smm_sync.log 2>&1
 */
#[AsCommand(
    name: 'app:sync-order-status',
    description: 'Sincroniza status de pedidos ativos com os providers SMM.'
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
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Máximo de pedidos a processar por execução', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $orders = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.service', 's')
            ->where('o.status IN (:statuses)')
            ->andWhere('s.providerSlug IS NOT NULL')
            ->setParameter('statuses', [Order::STATUS_PROCESSING, Order::STATUS_IN_PROGRESS])
            ->setMaxResults($limit)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

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
                $provider = $this->registry->get($slug);
                $status   = $provider->getOrderStatus($order->getExternalOrderId());

                $order->setStartCount($status['start_count']);
                $order->setRemains($status['remains']);

                $newStatus = $this->mapProviderStatus($status['status']);
                if ($newStatus !== $order->getStatus()) {
                    $order->setStatus($newStatus);
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('SyncOrderStatus: erro ao sincronizar pedido.', [
                    'order_id' => $order->getId(),
                    'error'    => $e->getMessage(),
                ]);
            }

            $io->progressAdvance();
        }

        $this->em->flush();
        $io->progressFinish();

        $io->success(sprintf('Sincronizados: %d | Erros: %d', $updated, $errors));

        return Command::SUCCESS;
    }

    /**
     * Mapeia status retornado pelo provider para o enum interno do Order.
     * Providers diferentes podem usar strings ligeiramente distintas;
     * este método normaliza tudo.
     */
    private function mapProviderStatus(string $raw): string
    {
        return match (strtolower($raw)) {
            'pending'     => Order::STATUS_PENDING,
            'processing'  => Order::STATUS_PROCESSING,
            'in progress',
            'inprogress'  => Order::STATUS_IN_PROGRESS,
            'completed'   => Order::STATUS_COMPLETED,
            'partial'     => Order::STATUS_PARTIAL,
            'cancelled',
            'canceled'    => Order::STATUS_CANCELLED,
            'refunded'    => Order::STATUS_REFUNDED,
            default       => Order::STATUS_PROCESSING,
        };
    }
}
