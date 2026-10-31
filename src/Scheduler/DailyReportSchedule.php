<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Envia relatório financeiro diário para o administrador.
 * Executa todo dia às 07:00.
 */
#[AsCronTask('0 7 * * *')]
final class DailyReportSchedule
{
    public function __construct(
        private readonly PaymentRepository      $paymentRepository,
        private readonly OrderRepository        $orderRepository,
        private readonly MailerInterface        $mailer,
        private readonly LoggerInterface        $logger,
        private readonly string                 $adminEmail,
    ) {}

    public function __invoke(): void
    {
        $yesterday  = new \DateTimeImmutable('yesterday');
        $todayStart = new \DateTimeImmutable('today');

        $revenue   = $this->paymentRepository->sumApprovedSince($yesterday) - $this->paymentRepository->sumApprovedSince($todayStart);
        $expenses  = $this->paymentRepository->sumExpensesSince($yesterday) - $this->paymentRepository->sumExpensesSince($todayStart);
        $fees      = $this->paymentRepository->sumFeesSince($yesterday) - $this->paymentRepository->sumFeesSince($todayStart);

        try {
            $email = (new TemplatedEmail())
                ->to(new Address($this->adminEmail))
                ->subject('[PulseSMM] Relatório diário — ' . $yesterday->format('d/m/Y'))
                ->htmlTemplate('emails/daily_report.html.twig')
                ->context([
                    'date'        => $yesterday,
                    'revenue'     => $revenue,
                    'expenses'    => $expenses,
                    'fees'        => $fees,
                    'netProfit'   => $revenue - $expenses - $fees,
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('DailyReport: falha ao enviar.', ['error' => $e->getMessage()]);
        }
    }
}
