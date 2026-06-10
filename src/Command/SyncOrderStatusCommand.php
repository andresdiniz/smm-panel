<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OrderSyncService;
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
 * Em produção o sync automático é feito pelo Scheduler (OrderSyncSchedule).
 * Este comando continua disponível para debug e execução manual pontual.
 */
#[AsCommand(
    name: 'app:sync-order-status',
    description: 'Sincroniza status de pedidos ativos com os providers SMM (direto, sem fila).'
)]
final class SyncOrderStatusCommand extends Command
{
    public function __construct(
        private readonly OrderSyncService $sync,
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

        try {
            if ($orderId !== null) {
                $result = $this->sync->syncOne((int) $orderId);
            } else {
                $result = $this->sync->syncBatch($limit);
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($result['processed'] === 0) {
            $io->info('Nenhum pedido ativo para sincronizar.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Processados: %d | Atualizados: %d | Erros: %d',
            $result['processed'],
            $result['updated'],
            $result['errors'],
        ));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
