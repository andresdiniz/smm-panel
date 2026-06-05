<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'smm:finance:daily-report',
    description: 'Gera relatório financeiro diário no console.',
)]
final class DailyFinanceReportCommand extends Command
{
    public function __construct(
        private readonly OrderRepository   $orders,
        private readonly PaymentRepository $payments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Data YYYY-MM-DD (padrão: ontem)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $date = $input->getOption('date')
            ? new \DateTimeImmutable($input->getOption('date'))
            : new \DateTimeImmutable('yesterday');

        $from = $date->setTime(0, 0);
        $to   = $date->setTime(23, 59, 59);

        $summary = $this->orders->financialSummary($from, $to);
        $fees    = $this->payments->feeReportByGateway($from, $to);

        $io->title('Relatório Financeiro — ' . $date->format('d/m/Y'));
        $io->table(
            ['Métrica', 'Valor'],
            [
                ['Total de pedidos', $summary['total_orders'] ?? 0],
                ['Receita bruta', 'R$ ' . number_format(($summary['total_revenue'] ?? 0) / 100, 2, ',', '.')],
                ['Custo providers', 'R$ ' . number_format(($summary['total_cost'] ?? 0) / 100, 2, ',', '.')],
                ['Lucro bruto', 'R$ ' . number_format(($summary['total_profit'] ?? 0) / 100, 2, ',', '.')],
            ]
        );

        if ($fees) {
            $io->section('Taxas por Gateway');
            $io->table(
                ['Gateway', 'Transações', 'Total cobrado', 'Taxas', 'Líquido'],
                array_map(fn($r) => [
                    $r['gateway'],
                    $r['total_transactions'],
                    'R$ ' . number_format($r['total_amount'] / 100, 2, ',', '.'),
                    'R$ ' . number_format($r['total_fees'] / 100, 2, ',', '.'),
                    'R$ ' . number_format($r['total_net'] / 100, 2, ',', '.'),
                ], $fees)
            );
        }

        return Command::SUCCESS;
    }
}
