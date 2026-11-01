<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CronLog;
use App\Repository\CronLogRepository;
use App\Repository\MessengerHealthRepository;
use App\Repository\OrderLogRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cron-logs', name: 'admin_cron_log_')]
#[IsGranted('ROLE_ADMIN')]
class AdminCronLogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $qb = $this->em->getRepository(CronLog::class)
            ->createQueryBuilder('c')
            ->orderBy('c.startedAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 50);
        return $this->render('admin/cron_log/index.html.twig', ['pagination' => $pagination]);
    }

    #[Route('/saude', name: 'health', methods: ['GET'])]
    public function health(
        CronLogRepository $cronLogs,
        OrderLogRepository $orderLogs,
        MessengerHealthRepository $messengerHealth,
        PaymentRepository $payments,
    ): Response {
        $connection = $this->em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $schedulerHeartbeat = $cronLogs->findOneBy(['command' => 'scheduler:main:heartbeat']);
        $schedulerHeartbeatFresh = $schedulerHeartbeat !== null
            && $schedulerHeartbeat->getStartedAt() >= new \DateTimeImmutable('-3 minutes');
        $workerHeartbeats = array_map(
            static fn (array $worker): array => $worker + [
                'is_fresh' => new \DateTimeImmutable($worker['last_seen_at']) >= new \DateTimeImmutable('-3 minutes'),
            ],
            $messengerHealth->getWorkerHeartbeats(),
        );
        $failedMessengerMessages = null;
        if ($schemaManager->tablesExist(['messenger_messages'])) {
            $failedMessengerMessages = (int) $connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('messenger_messages')
                ->where('queue_name = :queueName')
                ->setParameter('queueName', 'failed')
                ->executeQuery()
                ->fetchOne();
        }

        return $this->render('admin/cron_log/health.html.twig', [
            'cronErrors' => $cronLogs->findRecentErrors(24),
            'orderErrors' => $orderLogs->findRecentErrors(24),
            'cronSummary' => $cronLogs->getSummary(),
            'schedulerHeartbeat' => $schedulerHeartbeat,
            'schedulerHeartbeatFresh' => $schedulerHeartbeatFresh,
            'failedMessengerMessages' => $failedMessengerMessages,
            'queueDepths' => $messengerHealth->getQueueDepths(),
            'workerHeartbeats' => $workerHeartbeats,
            'stalePayments' => $payments->findPendingOlderThan(new \DateTimeImmutable('-30 minutes'), 20),
        ]);
    }
}
