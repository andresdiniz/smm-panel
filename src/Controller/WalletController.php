<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\DynamicGatewayLoader;
use App\Billing\Exception\GatewayException;
use App\Entity\Payment;
use App\Entity\ProviderCredential;
use App\Repository\PaymentRepository;
use App\Repository\ProviderCredentialRepository;
use App\Repository\WalletRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[IsGranted('ROLE_USER')]
#[Route('/wallet', name: 'app_wallet_')]
class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletRepository             $walletRepository,
        private readonly PaymentRepository            $paymentRepository,
        private readonly DynamicGatewayLoader         $gatewayLoader,
        private readonly ProviderCredentialRepository $credentialRepository,
        private readonly LoggerInterface              $logger,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $wallet       = $this->walletRepository->findOneByUser($this->getUser());
        $transactions = $this->paymentRepository->findRecentByUser($this->getUser(), 20);

        return $this->render('wallet/index.html.twig', [
            'wallet'       => $wallet,
            'transactions' => $transactions,
        ]);
    }

    #[Route('/deposit', name: 'deposit', methods: ['GET', 'POST'])]
    public function deposit(Request $request): Response
    {
        $user        = $this->getUser();
        $wallet      = $this->walletRepository->findOneByUser($user);

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

            $token = $request->request->get('_token');
            if (!is_string($token) || !$this->isCsrfTokenValid('wallet_deposit', $token)) {
                $this->addFlash('error', 'Token inválido. Tente novamente.');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $rawAmount = $request->request->get('amount', '');
            $method = $request->request->get('method', 'pix');
            if (
                !is_string($rawAmount)
                || preg_match('/^\d+(?:[.,]\d{1,2})?$/', $rawAmount) !== 1
                || !is_string($method)
                || !in_array($method, ['pix', 'credit_card', 'debit_card'], true)
            ) {
                $this->addFlash('error', 'Valor ou método de pagamento inválido.');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $amountCents = $this->parseAmountCents($rawAmount);
            if ($amountCents === null || $amountCents < 1000) {
                $this->addFlash('error', 'Valor mínimo de depósito: R$ 10,00');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $cpfInput = $request->request->get('cpf', '');
            $phoneInput = $request->request->get('phone', '');
            if (!is_string($cpfInput) || !is_string($phoneInput)) {
                $this->addFlash('error', 'CPF ou telefone inválido.');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $cpf = preg_replace('/\D/', '', $cpfInput);
            $rawPhone = preg_replace('/\D/', '', $phoneInput);
            $phone    = $rawPhone !== '' ? '+55' . $rawPhone : '';

            // Prioridade: campo do formulário > primeiro gateway ativo no banco
            $requestedGateway = $request->request->get('gateway', '');
            if (!is_string($requestedGateway)) {
                $this->addFlash('error', 'Gateway de pagamento inválido.');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $gatewaySlug = $requestedGateway !== '' ? $requestedGateway : $this->resolveDefaultGateway();

            if ($gatewaySlug === null) {
                $this->addFlash(
                    'error',
                    'Nenhum gateway de pagamento configurado. Acesse o painel administrativo e cadastre uma credencial.',
                );
                return $this->redirectToRoute('app_wallet_deposit');
            }

            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $gatewaySlug) !== 1) {
                $this->addFlash('error', 'Gateway de pagamento inválido.');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            try {
                $gateway = $this->gatewayLoader->load($gatewaySlug);
            } catch (\InvalidArgumentException|\RuntimeException) {
                $this->addFlash(
                    'error',
                    'Gateway não encontrado. Cadastre a credencial no painel administrativo.',
                );
                return $this->redirectToRoute('app_wallet_deposit');
            }

            try {
                $payment = $gateway->createDeposit($user, $amountCents, $method, $cpf, $phone);
            } catch (GatewayException $e) {
                $this->logger->warning('Falha ao criar cobrança no gateway.', [
                    'gateway' => $gatewaySlug,
                    'method' => $method,
                    'exception' => $e::class,
                ]);
                $this->addFlash(
                    'error',
                    'Não foi possível confirmar a cobrança. Verifique a carteira antes de tentar novamente para evitar duplicidade.',
                );
                return $this->redirectToRoute('app_wallet_deposit');
            }

            if ($method === 'pix') {
                return $this->redirectToRoute('app_wallet_deposit', [
                    'pix' => $payment->getId(),
                ]);
            }

            return $this->redirectToRoute('app_wallet_index');
        }

        $pixPayment = null;
        $rawPaymentId = $request->query->get('pix');
        if ($rawPaymentId !== null) {
            $paymentId = filter_var($rawPaymentId, FILTER_VALIDATE_INT);
            $pixPayment = $paymentId !== false ? $this->paymentRepository->find($paymentId) : null;

            if (
                $pixPayment === null
                || $pixPayment->getUser() !== $user
                || $pixPayment->getMethod() !== Payment::METHOD_PIX
            ) {
                throw $this->createNotFoundException('Pagamento não encontrado.');
            }
        }

        return $this->render('wallet/deposit.html.twig', [
            'wallet'      => $wallet,
            'lastDeposit' => $this->paymentRepository->findLastDepositByUser($user),
            'pixPayment'  => $pixPayment,
        ]);
    }

    #[Route('/pix/{id}', name: 'pix_pending')]
    public function pixPending(int $id): Response
    {
        $payment = $this->paymentRepository->find($id);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }

        return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
    }

    #[Route('/pix/{id}/status', name: 'pix_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pixStatus(int $id): JsonResponse
    {
        $payment = $this->paymentRepository->find($id);

        if (!$payment || $payment->getUser() !== $this->getUser() || $payment->getMethod() !== Payment::METHOD_PIX) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }

        $response = $this->json(['status' => $payment->getStatus()]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Resolve o slug do gateway padrão consultando o banco.
     */
    private function resolveDefaultGateway(): ?string
    {
        $credentials = $this->credentialRepository->findAllActiveByType(ProviderCredential::TYPE_PAYMENT);

        foreach (['asaas', 'mercadopago', 'pagbank'] as $preferred) {
            if (isset($credentials[$preferred])) {
                return $preferred;
            }
        }

        $first = array_key_first($credentials);
        return $first ?? null;
    }

    private function parseAmountCents(string $amount): ?int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $amount), 2), 2, '0');
        $whole = ltrim($whole, '0') ?: '0';
        $maxWhole = (string) intdiv(PHP_INT_MAX, 100);

        if (
            strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)
        ) {
            return null;
        }

        $wholeCents = (int) $whole * 100;
        $fractionCents = (int) str_pad($fraction, 2, '0');
        if ($wholeCents > PHP_INT_MAX - $fractionCents) {
            return null;
        }

        return $wholeCents + $fractionCents;
    }
}
