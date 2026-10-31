<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/payment', name: 'admin_payment_')]
class PaymentActionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Aprova manualmente um pagamento pendente:
     *  1. Muda status do Payment para approved + seta paidAt
     *  2. Credita amountCents - feeCents na Wallet do usuário
     *  3. Registra WalletTransaction do tipo CREDIT
     */
    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(int $id, Request $request): RedirectResponse
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('approve_payment_'.$id, $token)) {
            $this->addFlash('danger', 'Token inválido. Recarregue a página.');
            return $this->redirectToAdminList($request);
        }

        $payment = $this->em->find(Payment::class, $id);

        if (!$payment) {
            $this->addFlash('danger', "Pagamento #{$id} não encontrado.");
            return $this->redirectToAdminList($request);
        }

        $result = $this->em->wrapInTransaction(function () use ($payment): array {
            $gatewayResponse = $payment->getGatewayResponse();
            $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if ($gatewayResponse !== null) {
                $payment->setGatewayResponse($gatewayResponse);
            }

            if ($payment->getStatus() !== Payment::STATUS_PENDING) {
                return ['status' => 'already_processed'];
            }

            $wallet = $this->em->createQueryBuilder()
                ->select('w')
                ->from(Wallet::class, 'w')
                ->where('w.user = :user')
                ->setParameter('user', $payment->getUser())
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();
            if ($wallet === null) {
                return ['status' => 'no_wallet'];
            }

            $netCents = $payment->getAmountCents() - $payment->getFeeCents();
            if ($netCents <= 0) {
                throw new \DomainException('O valor líquido do pagamento deve ser positivo.');
            }

            $wallet->credit($netCents);
            $transaction = (new WalletTransaction())
                ->setWallet($wallet)
                ->setType(TransactionType::CREDIT)
                ->setAmountCents($netCents)
                ->setBalanceAfterCents($wallet->getBalanceCents())
                ->setDescription(sprintf(
                    'Depósito aprovado manualmente (Payment #%d via %s)',
                    $payment->getId(),
                    $payment->getMethod(),
                ));

            $payment->approve();
            $this->em->persist($transaction);

            return ['status' => 'approved', 'amount' => $netCents];
        });

        if ($result['status'] !== 'approved') {
            $message = $result['status'] === 'no_wallet'
                ? sprintf('Usuário %s não possui carteira criada.', $payment->getUser()->getEmail())
                : sprintf('Pagamento #%d já não está pendente.', $id);
            $this->addFlash($result['status'] === 'no_wallet' ? 'danger' : 'warning', $message);
            return $this->redirectToDetail($request, $id);
        }

        $netCents = $result['amount'];

        $this->addFlash('success', sprintf(
            'Pagamento #%d aprovado. R$ %.2f creditados para %s.',
            $id,
            $netCents / 100,
            $payment->getUser()->getName()
        ));

        return $this->redirectToDetail($request, $id);
    }

    /**
     * Cancela manualmente um pagamento pendente.
     * Não movimenta carteira — pagamento ainda não tinha sido creditado.
     */
    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request): RedirectResponse
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('cancel_payment_'.$id, $token)) {
            $this->addFlash('danger', 'Token inválido. Recarregue a página.');
            return $this->redirectToAdminList($request);
        }

        $payment = $this->em->find(Payment::class, $id);

        if (!$payment) {
            $this->addFlash('danger', "Pagamento #{$id} não encontrado.");
            return $this->redirectToAdminList($request);
        }

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            $this->addFlash('warning', sprintf(
                'Pagamento #%d não está pendente (status atual: %s).',
                $id,
                $payment->getStatus()
            ));
            return $this->redirectToDetail($request, $id);
        }

        $payment->setStatus(Payment::STATUS_CANCELLED);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Pagamento #%d cancelado com sucesso.',
            $id
        ));

        return $this->redirectToDetail($request, $id);
    }

    // ---------------------------------------------------------------------------
    // Helpers de redirecionamento para o EasyAdmin
    // ---------------------------------------------------------------------------

    private function redirectToDetail(Request $request, int $id): RedirectResponse
    {
        // Tenta redirecionar de volta para o detail do EasyAdmin
        // A URL do painel é /admin?crudAction=detail&crudControllerFqcn=...&entityId=...
        $referer = $request->headers->get('referer');
        if ($referer && str_contains($referer, '/admin')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin', [
            'crudAction'           => 'detail',
            'crudControllerFqcn'   => PaymentCrudController::class,
            'entityId'             => $id,
        ]);
    }

    private function redirectToAdminList(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('admin', [
            'crudAction'         => 'index',
            'crudControllerFqcn' => PaymentCrudController::class,
        ]);
    }
}
