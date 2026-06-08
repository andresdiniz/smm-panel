<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\DynamicGatewayLoader;
use App\Entity\ProviderCredential;
use App\Repository\PaymentRepository;
use App\Repository\ProviderCredentialRepository;
use App\Repository\WalletRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/wallet', name: 'app_wallet_')]
class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletRepository             $walletRepository,
        private readonly PaymentRepository            $paymentRepository,
        private readonly DynamicGatewayLoader         $gatewayLoader,
        private readonly ProviderCredentialRepository $credentialRepository,
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
        $lastDeposit = $this->paymentRepository->findLastDepositByUser($user);

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

            if (!$this->isCsrfTokenValid('wallet_deposit', $request->request->get('_token'))) {
                $this->addFlash('error', 'Token inválido. Tente novamente.');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $amountCents = (int) round((float) $request->request->get('amount', 0) * 100);
            $method      = $request->request->get('method', 'pix');

            if ($amountCents < 1000) {
                $this->addFlash('error', 'Valor mínimo de depósito: R$ 10,00');
                return $this->redirectToRoute('app_wallet_deposit');
            }

            // Sanitiza CPF: remove tudo que não for dígito
            $cpf = preg_replace('/\D/', '', (string) $request->request->get('cpf', ''));

            // Sanitiza telefone: remove tudo que não for dígito, garante DDI 55
            $rawPhone = preg_replace('/\D/', '', (string) $request->request->get('phone', ''));
            $phone    = $rawPhone !== '' ? '+55' . $rawPhone : '';

            // Prioridade: campo do formulário > primeiro gateway ativo no banco
            $gatewaySlug = $request->request->get('gateway') ?: $this->resolveDefaultGateway();

            if ($gatewaySlug === null) {
                $this->addFlash(
                    'error',
                    'Nenhum gateway de pagamento configurado. Acesse o painel administrativo e cadastre uma credencial.',
                );
                return $this->redirectToRoute('app_wallet_deposit');
            }

            try {
                $gateway = $this->gatewayLoader->load($gatewaySlug);
            } catch (\RuntimeException $e) {
                $this->addFlash(
                    'error',
                    sprintf('Gateway "%s" não encontrado no banco. Cadastre a credencial no painel admin.', $gatewaySlug),
                );
                return $this->redirectToRoute('app_wallet_deposit');
            }

            $payment = $gateway->createDeposit($user, $amountCents, $method, $cpf, $phone);

            if ($method === 'pix') {
                return $this->redirectToRoute('app_wallet_pix_pending', [
                    'id' => $payment->getId(),
                ]);
            }

            return $this->redirectToRoute('app_wallet_index');
        }

        return $this->render('wallet/deposit.html.twig', [
            'wallet'      => $wallet,
            'lastDeposit' => $lastDeposit,
        ]);
    }

    #[Route('/pix/{id}', name: 'pix_pending')]
    public function pixPending(int $id): Response
    {
        $payment = $this->paymentRepository->find($id);

        if (!$payment || $payment->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }

        return $this->render('wallet/pix_pending.html.twig', [
            'payment'      => $payment,
            'qrCodeBase64' => $payment->getQrCodeBase64(),
        ]);
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
}
