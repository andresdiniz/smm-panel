<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\Billing\ReconcilePaymentMessage;
use App\Repository\PaymentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'smm:billing:reconcile',
    description: 'Reconcilia pagamentos Pix pendentes consultando o gateway.',
)]
final class BillingReconcileCommand extends Command
{
    public function __construct(
        private readonly PaymentRepository   $payments,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $expired  = $this->payments->findExpiredPix(30);

        foreach ($expired as $payment) {
            $this->bus->dispatch(new ReconcilePaymentMessage($payment->getId()));
        }

        $io->success(sprintf('%d pagamento(s) enviados para reconciliação.', count($expired)));
        return Command::SUCCESS;
    }
}
