<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
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
    #[Route('/{id}/approve', name: 'approve', methods: ['GET'])]
    public function approve(int $id, Request $request): RedirectResponse
    {
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

        $wallet = $payment->getUser()->getWallet();

        if (!$wallet) {
            $this->addFlash('danger', sprintf(
                'Usuário %s não possui carteira criada.',
                $payment->getUser()->getEmail()
            ));
            return $this->redirectToDetail($request, $id);
        }

        // Valor líquido: descontando a taxa do gateway
        $netCents = $payment->getAmountCents() - $payment->getFeeCents();

        // 1. Aprovar o pagamento
        $payment->approve(); // seta status = approved + paidAt = now()

        // 2. Creditar na carteira
        $wallet->credit($netCents);

        // 3. Registrar transação
        $tx = (new WalletTransaction())
            ->setWallet($wallet)
            ->setType(TransactionType::CREDIT)
            ->setAmountCents($netCents)
            ->setBalanceAfterCents($wallet->getBalanceCents())
            ->setDescription(sprintf(
                'Depósito aprovado manualmente (Payment #%d via %s)',
                $payment->getId(),
                $payment->getMethod()
            ));

        $this->em->persist($tx);
        $this->em->flush();

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
    #[Route('/{id}/cancel', name: 'cancel', methods: ['GET'])]
    public function cancel(int $id, Request $request): RedirectResponse
    {
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
