<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\OrderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'smm:orders:sync-stale',
    description: 'Despacha SyncOrderStatusMessage para pedidos parados sem atualização.',
)]
final class SyncStaleOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderRepository    $orders,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('minutes', null, InputOption::VALUE_OPTIONAL, 'Minutos sem atualização', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $minutes = (int) $input->getOption('minutes');
        $orders  = $this->orders->findStale($minutes);

        if (empty($orders)) {
            $io->success('Nenhum pedido parado encontrado.');
            return Command::SUCCESS;
        }

        foreach ($orders as $order) {
            $this->bus->dispatch(new SyncOrderStatusMessage($order->getId()));
            $io->writeln("Dispatched sync for Order #{$order->getId()}");
        }

        $io->success(sprintf('%d pedido(s) reenviados para sync.', count($orders)));
        return Command::SUCCESS;
    }
}
