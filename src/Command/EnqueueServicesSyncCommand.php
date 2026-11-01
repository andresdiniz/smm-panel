<?php

declare(strict_types=1);

namespace App\Command;

use App\Scheduler\SyncServicesMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'smm:services:sync-async',
    description: 'Enfileira a sincronização dos limites dos serviços nos providers SMM.',
)]
final class EnqueueServicesSyncCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('provider', 'p', InputOption::VALUE_REQUIRED, 'Sincroniza apenas este slug de provider.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = $input->getOption('provider');
        $provider = is_string($provider) ? strtolower(trim($provider)) : null;

        if ($provider !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $provider) !== 1) {
            $io->error('Slug de provider inválido.');
            return Command::INVALID;
        }

        try {
            $this->bus->dispatch(new SyncServicesMessage($provider));
        } catch (\Throwable $e) {
            $io->error('Não foi possível enfileirar a sincronização: '.$e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Sincronização enfileirada em orders_low%s.',
            $provider === null ? '' : ' para o provider '.$provider,
        ));
        $io->note('O worker do Messenger precisa estar ativo para processar a mensagem.');

        return Command::SUCCESS;
    }
}
