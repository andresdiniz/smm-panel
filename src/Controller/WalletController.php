<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\DynamicGatewayLoader;
use App\Billing\Exception\GatewayException;
use App\Billing\PixPaymentCancellationInterface;
use App\Billing\PixQrCodeRefreshInterface;
use App\Entity\Payment;
use App\Entity\ProviderCredential;
use App\Entity\Wallet;
use App\Repository\PaymentRepository;
use App\Repository\ProviderCredentialRepository;
use App\Repository\WalletRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.wallet_pix_qr_refresh')]
        private readonly RateLimiterFactory $pixQrRefreshLimiter,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $user = $this->getUser();
        $wallet = $this->walletRepository->findOneByUser($user)
            ?? (new Wallet())->setUser($user);
        $transactions = $this->paymentRepository->findRecentByUser($user, 20);

        return $this->render('wallet/index.html.twig', [
            'wallet'       => $wallet,
            'transactions' => $transactions,
        ]);
    }

    #[Route('/deposit', name: 'deposit', methods: ['GET', 'POST'])]
    public function deposit(Request $request): Response
    {
        $user = $this->getUser();
        $wallet = $this->walletRepository->findOneByUser($user)
            ?? (new Wallet())->setUser($user);

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
                $context = $e->getContext();
                $this->logger->warning('Falha ao criar cobrança no gateway.', [
                    'gateway' => $gatewaySlug,
                    'method' => $method,
                    'operation' => $context['operation'] ?? 'create_deposit',
                    'reason' => $context['reason'] ?? 'gateway_error',
                    'http_status' => $context['http_status'] ?? null,
                    'missing_fields' => $context['missing_fields'] ?? [],
                    'exception' => $e::class,
                ]);
                $this->addFlash('error', $this->gatewayFailureMessage($context));
                return $this->redirectToRoute('app_wallet_deposit');
            }

            if ($method === 'pix') {
                if ($payment->getPixCode() === null && $payment->getQrCodeBase64() === null) {
                    $gatewayResponse = json_decode($payment->getGatewayResponse() ?? '{}', true);
                    $qrFailure = is_array($gatewayResponse) && is_array($gatewayResponse['pixQrFailure'] ?? null)
                        ? $gatewayResponse['pixQrFailure']
                        : [];
                    $this->logger->warning('Cobrança Pix criada, mas o QR Code ainda não está disponível.', [
                        'gateway' => $gatewaySlug,
                        'payment_id' => $payment->getId(),
                        'operation' => $qrFailure['operation'] ?? 'fetch_pix_qr',
                        'reason' => $qrFailure['reason'] ?? 'qr_data_missing',
                        'http_status' => $qrFailure['http_status'] ?? null,
                    ]);
                }

                return $this->redirectToRoute('app_wallet_deposit', [
                    'pix' => $payment->getId(),
                ]);
            }

            return $this->redirectToRoute('app_wallet_index');
        }

        $pixPayment = null;
        $canRefreshPix = false;
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

            $gatewayResponse = json_decode($pixPayment->getGatewayResponse() ?? '{}', true);
            $canRefreshPix = $pixPayment->getStatus() === Payment::STATUS_PENDING
                && $this->isAsaasPixPayment($pixPayment, $gatewayResponse);
        }

        return $this->render('wallet/deposit.html.twig', [
            'wallet'      => $wallet,
            'lastDeposit' => $this->paymentRepository->findLastDepositByUser($user),
            'pixPayment'  => $pixPayment,
            'canRefreshPix' => $canRefreshPix,
            'canCancelPix' => $canRefreshPix,
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

    #[Route('/pix/{id}/cancel', name: 'pix_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancelPix(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $payment = $this->paymentRepository->find($id);
        if (
            !$payment
            || $payment->getUser() !== $this->getUser()
            || $payment->getType() !== Payment::TYPE_DEPOSIT
            || $payment->getMethod() !== Payment::METHOD_PIX
        ) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('wallet_pix_cancel_'.$id, $token)) {
            $this->addFlash('error', 'Não foi possível validar a solicitação. Tente novamente.');

            return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
        }

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            $this->addFlash('error', 'Este Pix não está mais pendente e não pode ser cancelado.');

            return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
        }

        $gatewayResponse = json_decode($payment->getGatewayResponse() ?? '{}', true);
        if (!$this->isAsaasPixPayment($payment, $gatewayResponse)) {
            $this->addFlash('error', 'O cancelamento pelo painel está disponível somente para cobranças Pix do Asaas.');

            return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
        }

        try {
            $gateway = $this->gatewayLoader->load('asaas');
        } catch (\InvalidArgumentException|\RuntimeException) {
            $this->addFlash('error', 'O gateway Asaas não está disponível no momento.');

            return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
        }

        if (!$gateway instanceof PixPaymentCancellationInterface) {
            $this->addFlash('error', 'O cancelamento de Pix não está disponível no momento.');

            return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
        }

        try {
            $gateway->cancelPix($payment);
        } catch (GatewayException $e) {
            $context = $e->getContext();
            $this->logger->warning('Falha ao cancelar cobrança Pix.', [
                'gateway' => 'asaas',
                'payment_id' => $payment->getId(),
                'operation' => $context['operation'] ?? 'cancel_pix',
                'reason' => $context['reason'] ?? 'gateway_error',
                'http_status' => $context['http_status'] ?? null,
                'exception' => $e::class,
            ]);

            $this->addFlash(
                'error',
                ($context['reason'] ?? null) === 'payment_not_pending'
                    ? 'O status deste Pix mudou. Atualize a carteira para conferir.'
                    : 'Não foi possível cancelar o Pix no Asaas. A cobrança continua disponível até a confirmação do cancelamento.',
            );

            return $this->redirectToRoute('app_wallet_deposit', ['pix' => $id]);
        }

        $this->addFlash('success', 'Cobrança Pix cancelada com sucesso. Nenhum valor foi estornado ou debitado.');

        return $this->redirectToRoute('app_wallet_index');
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

    #[Route('/pix/{id}/qr', name: 'pix_qr_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refreshPixQr(int $id, Request $request): JsonResponse
    {
        $payment = $this->paymentRepository->find($id);
        if (
            !$payment
            || $payment->getUser() !== $this->getUser()
            || $payment->getMethod() !== Payment::METHOD_PIX
        ) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }

        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('wallet_pix_qr_'.$id, $token)) {
            return $this->noStore($this->json(['message' => 'Solicitação inválida.'], Response::HTTP_FORBIDDEN));
        }

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            return $this->noStore($this->json([
                'message' => 'Este Pix não está mais pendente.',
            ], Response::HTTP_CONFLICT));
        }

        if ($payment->getPixCode() !== null && $payment->getQrCodeBase64() !== null) {
            return $this->noStore($this->json([
                'pixCode' => $payment->getPixCode(),
                'qrCodeBase64' => $payment->getQrCodeBase64(),
            ]));
        }

        $refreshLimit = $this->pixQrRefreshLimiter
            ->create((string) $payment->getUser()->getId())
            ->consume();
        if (!$refreshLimit->isAccepted()) {
            $response = $this->json([
                'message' => 'Muitas tentativas de atualização. Aguarde um minuto e tente novamente.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = $refreshLimit->getRetryAfter();
            if ($retryAfter !== null) {
                $response->headers->set('Retry-After', (string) max(1, $retryAfter->getTimestamp() - time()));
            }

            return $this->noStore($response);
        }

        $gatewayResponse = json_decode($payment->getGatewayResponse() ?? '{}', true);
        if (!$this->isAsaasPixPayment($payment, $gatewayResponse)) {
            return $this->noStore($this->json([
                'message' => 'A atualização do QR Code não está disponível para este pagamento.',
            ], Response::HTTP_CONFLICT));
        }

        try {
            $gateway = $this->gatewayLoader->load('asaas');
        } catch (\InvalidArgumentException|\RuntimeException) {
            return $this->noStore($this->json([
                'message' => 'O gateway Asaas não está disponível no momento.',
            ], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        if (!$gateway instanceof PixQrCodeRefreshInterface) {
            return $this->noStore($this->json([
                'message' => 'A atualização do QR Code não está disponível no momento.',
            ], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        try {
            $gateway->refreshPixData($payment);
        } catch (GatewayException $e) {
            $context = $e->getContext();
            $this->logger->warning('Falha ao atualizar QR Code Pix.', [
                'gateway' => 'asaas',
                'payment_id' => $payment->getId(),
                'operation' => $context['operation'] ?? 'refresh_pix_qr',
                'reason' => $context['reason'] ?? 'gateway_error',
                'http_status' => $context['http_status'] ?? null,
                'exception' => $e::class,
            ]);

            return $this->noStore($this->json([
                'message' => $this->qrRefreshFailureMessage($context),
            ], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->noStore($this->json([
            'pixCode' => $payment->getPixCode(),
            'qrCodeBase64' => $payment->getQrCodeBase64(),
        ]));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function gatewayFailureMessage(array $context): string
    {
        return match ($context['reason'] ?? null) {
            'cpf_required', 'cpf_invalid' => 'Informe um CPF válido para gerar a cobrança.',
            'missing_credentials' => 'O gateway de pagamento está sem credenciais configuradas. Avise o suporte.',
            default => match ($context['http_status'] ?? null) {
                401, 403 => 'O gateway recusou as credenciais. Verifique a chave de API e o ambiente configurado.',
                429 => 'O gateway está processando muitas solicitações. Aguarde alguns instantes antes de tentar novamente.',
                default => 'Não foi possível gerar a cobrança. Verifique sua carteira antes de tentar novamente para evitar duplicidade.',
            },
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function qrRefreshFailureMessage(array $context): string
    {
        return match ($context['http_status'] ?? null) {
            401, 403 => 'O QR Code não pôde ser carregado. Verifique as credenciais do Asaas.',
            429 => 'O gateway está ocupado. Aguarde alguns instantes e tente novamente.',
            default => 'O QR Code ainda não está disponível. Aguarde alguns instantes e tente novamente.',
        };
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Recognize older Asaas charges created before gateway metadata was persisted.
     *
     * @param mixed $gatewayResponse
     */
    private function isAsaasPixPayment(Payment $payment, mixed $gatewayResponse): bool
    {
        if (!is_array($gatewayResponse)) {
            return false;
        }

        return ($gatewayResponse['gateway'] ?? null) === 'asaas'
            || (
                ($gatewayResponse['billingType'] ?? null) === 'PIX'
                && ($gatewayResponse['id'] ?? null) === $payment->getExternalId()
            );
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
