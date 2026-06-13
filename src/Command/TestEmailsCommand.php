<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SendDepositConfirmedEmailMessage;
use App\Message\SendOrderCompletedEmailMessage;
use App\Message\SendWelcomeEmailMessage;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:test-emails',
    description: 'Dispara todos os e-mails transacionais para teste (welcome, deposit, order completed)',
)]
class TestEmailsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly UserRepository      $userRepo,
        private readonly OrderRepository     $orderRepo,
        private readonly PaymentRepository   $paymentRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id',    null, InputOption::VALUE_REQUIRED, 'ID do usuário para welcome e-mail', 1)
            ->addOption('order-id',   null, InputOption::VALUE_REQUIRED, 'ID do pedido para order-completed e-mail')
            ->addOption('payment-id', null, InputOption::VALUE_REQUIRED, 'ID do pagamento para deposit-confirmed e-mail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Teste de E-mails Transacionais');

        $userId    = (int) $input->getOption('user-id');
        $orderId   = $input->getOption('order-id');
        $paymentId = $input->getOption('payment-id');

        // ── Resolve IDs automáticos se não informados ──
        $user = $this->userRepo->find($userId);
        if (!$user) {
            $io->error("Usuário ID {$userId} não encontrado.");
            return Command::FAILURE;
        }

        if (!$orderId) {
            $order = $this->orderRepo->findOneBy([], ['id' => 'DESC']);
            $orderId = $order?->getId();
        }

        if (!$paymentId) {
            $payment = $this->paymentRepo->findOneBy([], ['id' => 'DESC']);
            $paymentId = $payment?->getId();
        }

        $io->section('IDs utilizados no teste');
        $io->table(
            ['E-mail', 'ID usado'],
            [
                ['Welcome',           "user #{$userId} ({$user->getEmail()})"],
                ['Deposit Confirmed',  $paymentId ? "payment #{$paymentId}" : 'NENHUM PAGAMENTO ENCONTRADO'],
                ['Order Completed',    $orderId   ? "order #{$orderId}"     : 'NENHUM PEDIDO ENCONTRADO'],
            ]
        );

        if (!$io->confirm('Disparar os e-mails acima?', true)) {
            $io->warning('Cancelado.');
            return Command::SUCCESS;
        }

        // ── 1. Welcome ──
        $io->text('Enviando Welcome e-mail...');
        try {
            $this->bus->dispatch(new SendWelcomeEmailMessage($userId));
            $io->success('Welcome e-mail despachado.');
        } catch (\Throwable $e) {
            $io->error('Welcome FALHOU: ' . $e->getMessage());
        }

        // ── 2. Deposit Confirmed ──
        if ($paymentId) {
            $io->text('Enviando Deposit Confirmed e-mail...');
            try {
                $this->bus->dispatch(new SendDepositConfirmedEmailMessage((int) $paymentId));
                $io->success('Deposit Confirmed e-mail despachado.');
            } catch (\Throwable $e) {
                $io->error('Deposit Confirmed FALHOU: ' . $e->getMessage());
            }
        } else {
            $io->warning('Deposit Confirmed pulado: nenhum pagamento encontrado no banco.');
        }

        // ── 3. Order Completed ──
        if ($orderId) {
            $io->text('Enviando Order Completed e-mail...');
            try {
                $this->bus->dispatch(new SendOrderCompletedEmailMessage((int) $orderId));
                $io->success('Order Completed e-mail despachado.');
            } catch (\Throwable $e) {
                $io->error('Order Completed FALHOU: ' . $e->getMessage());
            }
        } else {
            $io->warning('Order Completed pulado: nenhum pedido encontrado no banco.');
        }

        $io->note('Os e-mails foram colocados na fila. O worker (messenger:consume) vai enviá-los em segundos.');
        $io->text('Acompanhe: tail -f var/log/prod.log | grep -i mail');

        return Command::SUCCESS;
    }
}
