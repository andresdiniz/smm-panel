<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Billing\DynamicGatewayLoader;
use App\Billing\Exception\GatewayException;
use App\Billing\PaymentRefundInterface;
use App\Entity\Payment;
use App\Entity\PaymentRefundRequest;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use App\Repository\PaymentRefundRequestRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/estornos', name: 'admin_payment_refund_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPaymentRefundController extends AbstractController
{
    public function __construct(
        private readonly PaymentRefundRequestRepository $refundRequests,
        private readonly EntityManagerInterface $em,
        private readonly DynamicGatewayLoader $gatewayLoader,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/payment_refund/index.html.twig', [
            'refundRequests' => $this->refundRequests->findLatest(),
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        $refundRequest = $this->refundRequests->find($id);
        if (!$refundRequest) {
            throw $this->createNotFoundException();
        }

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('approve_refund_'.$id, $token)) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        $note = trim($request->request->getString('admin_note'));
        if (mb_strlen($note) > 2000) {
            $this->addFlash('error', 'A observação deve ter no máximo 2.000 caracteres.');
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        try {
            $gateway = $this->gatewayLoader->load('asaas');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->logger->error('Gateway Asaas não está disponível para estorno.', [
                'refund_request_id' => $refundRequest->getId(),
                'exception' => $e::class,
            ]);
            $this->addFlash('error', 'O gateway Asaas não está configurado. Nenhum valor foi reservado.');
            return $this->redirectToRoute('admin_payment_refund_index');
        }
        if (!$gateway instanceof PaymentRefundInterface) {
            $this->addFlash('error', 'O gateway Asaas não oferece suporte a estorno. Nenhum valor foi reservado.');
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        try {
            $this->em->wrapInTransaction(function () use ($refundRequest, $note): void {
                $this->em->refresh($refundRequest, LockMode::PESSIMISTIC_WRITE);
                $payment = $refundRequest->getPayment();
                $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);

                if ($refundRequest->getStatus() !== PaymentRefundRequest::STATUS_PENDING
                    || $payment->getType() !== Payment::TYPE_DEPOSIT
                    || $payment->getStatus() !== Payment::STATUS_APPROVED
                ) {
                    throw new \DomainException('A solicitação ou o pagamento já não está pendente de revisão.');
                }

                $gatewayData = json_decode($payment->getGatewayResponse() ?? '{}', true);
                if (!is_array($gatewayData) || ($gatewayData['gateway'] ?? null) !== 'asaas') {
                    throw new \DomainException('Não foi possível confirmar que este pagamento foi criado pelo Asaas.');
                }
                if ($payment->getExternalId() === null || $payment->getExternalId() === '') {
                    throw new \DomainException('O pagamento não tem identificador válido no Asaas.');
                }

                $net = $payment->getAmountCents() - $payment->getFeeCents();
                if ($net <= 0) {
                    throw new \DomainException('O valor líquido deste pagamento não é válido para estorno.');
                }

                /** @var Wallet|null $wallet */
                $wallet = $this->em->createQueryBuilder()
                    ->select('w')
                    ->from(Wallet::class, 'w')
                    ->where('w.user = :user')
                    ->setParameter('user', $payment->getUser())
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getOneOrNullResult();

                if ($wallet === null || $wallet->getBalanceCents() < $net) {
                    throw new \DomainException('O saldo disponível do usuário é insuficiente para reservar o valor do estorno. Nenhuma solicitação foi enviada ao Asaas.');
                }

                $wallet->debit($net);
                $this->em->persist((new WalletTransaction())
                    ->setWallet($wallet)
                    ->setType(TransactionType::DEBIT)
                    ->setAmountCents($net)
                    ->setBalanceAfterCents($wallet->getBalanceCents())
                    ->setDescription(sprintf('Reserva de estorno do pagamento #%d', $payment->getId())));
                $refundRequest->markProcessing($note !== '' ? $note : 'Aprovado; solicitação enviada ao Asaas.');

            });
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        try {
            $gateway->requestRefund($refundRequest->getPayment());
        } catch (GatewayException $e) {
            $context = $e->getContext();
            $this->logger->error('Solicitação de estorno não confirmada pelo Asaas; valor mantido reservado para reconciliação manual.', [
                'refund_request_id' => $refundRequest->getId(),
                'payment_id' => $refundRequest->getPayment()->getId(),
                'reason' => $context['reason'] ?? 'gateway_error',
                'http_status' => $context['http_status'] ?? null,
            ]);
            $this->setRefundNote($refundRequest, 'Resposta do Asaas não confirmada. Saldo reservado; verifique o painel Asaas antes de qualquer nova ação.');
            $this->addFlash('error', 'O Asaas não confirmou o estorno. O valor foi reservado e a solicitação exige reconciliação manual antes de nova tentativa.');
            return $this->redirectToRoute('admin_payment_refund_index');
        } catch (\RuntimeException $e) {
            $this->logger->error('Falha ao encaminhar solicitação de estorno ao Asaas.', [
                'refund_request_id' => $refundRequest->getId(),
                'payment_id' => $refundRequest->getPayment()->getId(),
                'exception' => $e::class,
            ]);
            $this->setRefundNote($refundRequest, 'Falha ao confirmar a resposta do gateway. Saldo reservado; verifique o painel Asaas.');
            $this->addFlash('error', 'Não foi possível confirmar a solicitação. O saldo foi reservado; verifique o painel Asaas antes de qualquer nova ação.');
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        $this->addFlash('success', 'Solicitação enviada ao Asaas. O status final será atualizado pelo webhook do gateway.');

        return $this->redirectToRoute('admin_payment_refund_index');
    }

    #[Route('/{id}/reject', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        $refundRequest = $this->refundRequests->find($id);
        if (!$refundRequest) {
            throw $this->createNotFoundException();
        }

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('reject_refund_'.$id, $token)) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        $note = trim($request->request->getString('admin_note'));
        if ($note === '' || mb_strlen($note) > 2000) {
            $this->addFlash('error', 'Informe o motivo da recusa (até 2.000 caracteres).');
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        try {
            $this->em->wrapInTransaction(function () use ($refundRequest, $note): void {
                $this->em->refresh($refundRequest, LockMode::PESSIMISTIC_WRITE);
                if ($refundRequest->getStatus() !== PaymentRefundRequest::STATUS_PENDING) {
                    throw new \DomainException('Somente solicitações pendentes podem ser recusadas.');
                }
                $refundRequest->reject($note);
            });
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_payment_refund_index');
        }

        $this->addFlash('success', 'Solicitação de estorno recusada.');

        return $this->redirectToRoute('admin_payment_refund_index');
    }

    private function setRefundNote(PaymentRefundRequest $refundRequest, string $note): void
    {
        $this->em->wrapInTransaction(function () use ($refundRequest, $note): void {
            $this->em->refresh($refundRequest, LockMode::PESSIMISTIC_WRITE);
            $refundRequest->setAdminNote($note);
        });
    }
}
