<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\OrderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Resgata pedidos presos enfileirando um SyncOrderStatusMessage para cada um.
 *
 * Uso:
 *   # Todos presos há mais de 30 min (padrão)
 *   php bin/console smm:orders:sync-stale
 *
 *   # Pedido específico (ex: #19)
 *   php bin/console smm:orders:sync-stale --order=19
 *
 *   # Presos há mais de 5 min
 *   php bin/console smm:orders:sync-stale --minutes=5
 */
#[AsCommand(
    name: 'smm:orders:sync-stale',
    description: 'Despacha SyncOrderStatusMessage para pedidos presos sem atualização.',
)]
final class SyncStaleOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderRepository     $orders,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'minutes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Minutos sem atualização para considerar o pedido preso',
                30
            )
            ->addOption(
                'order',
                null,
                InputOption::VALUE_OPTIONAL,
                'ID de um pedido específico para forçar o sync (ignora --minutes)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $orderId = $input->getOption('order');
        $minutes = (int) $input->getOption('minutes');

        // ── Modo pedido único ────────────────────────────────────────────
        if ($orderId !== null) {
            $this->bus->dispatch(new SyncOrderStatusMessage((int) $orderId));
            $io->success("SyncOrderStatusMessage despachado para Order #{$orderId}.");
            $io->note('Certifique-se de que o worker está rodando: php bin/console messenger:consume orders_high orders_medium orders_low -vv');
            return Command::SUCCESS;
        }

        // ── Modo lote ────────────────────────────────────────────────────
        $stale = $this->orders->findStale($minutes);

        if (empty($stale)) {
            $io->success("Nenhum pedido preso encontrado (threshold: {$minutes} min).");
            return Command::SUCCESS;
        }

        foreach ($stale as $order) {
            $this->bus->dispatch(new SyncOrderStatusMessage($order->getId()));
            $io->writeln(sprintf(
                '  → Order #%d [%s] preso desde %s',
                $order->getId(),
                $order->getStatus(),
                $order->getUpdatedAt()->format('H:i:s')
            ));
        }

        $io->success(sprintf('%d pedido(s) reenfileirados para sync.', count($stale)));
        return Command::SUCCESS;
    }
}
