<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:push:generate-vapid',
    description: 'Generates a VAPID key pair for browser push notifications.',
)]
final class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = VAPID::createVapidKeys();
        $io = new SymfonyStyle($input, $output);
        $io->warning('Store the private key only in the server environment or secret manager. Do not commit it.');
        $io->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $io->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $io->writeln('VAPID_SUBJECT=mailto:suporte@acheireviews.com.br');

        return Command::SUCCESS;
    }
}
