<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AffiliateCommission;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lida com toda a lógica de afiliados:
 *  - geração de código único
 *  - rastreamento do clique via sessão
 *  - vinculação no cadastro
 *  - criação de comissão ao confirmar pedido
 *  - pagamento de comissão (crédito na carteira)
 */
class AffiliateService
{
    public const SESSION_KEY = '_affiliate_ref';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack           $requestStack,
        private readonly UserRepository         $userRepository,
        private readonly WalletService          $walletService,
        private readonly float                  $defaultRate,  // injetado via services.yaml
    ) {}

    // ── Código único ─────────────────────────────────────────────────────────

    public function generateCode(): string
    {
        do {
            $code = substr(bin2hex(random_bytes(5)), 0, 8);
        } while ($this->userRepository->findOneBy(['affiliateCode' => $code]) !== null);

        return $code;
    }

    public function ensureCode(User $user): void
    {
        if ($user->getAffiliateCode() === null) {
            $user->setAffiliateCode($this->generateCode());
        }
    }

    // ── Rastreamento de clique ────────────────────────────────────────────────

    /** Chamado pelo AffiliateController quando alguém acessa /ref/{code} */
    public function trackClick(string $code): void
    {
        $session = $this->requestStack->getSession();
        // Não sobrescreve se já existe (first click wins)
        if (!$session->has(self::SESSION_KEY)) {
            $session->set(self::SESSION_KEY, $code);
        }
    }

    public function getSessionCode(): ?string
    {
        $session = $this->requestStack->getSession();
        return $session->get(self::SESSION_KEY);
    }

    public function clearSessionCode(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    // ── Vinculação no cadastro ────────────────────────────────────────────────

    /**
     * Lê o código da sessão e vincula o afiliado ao novo usuário.
     * Também garante que o próprio afiliado tenha seu código gerado.
     */
    public function linkAffiliate(User $newUser): void
    {
        $code = $this->getSessionCode();
        if ($code === null) {
            return;
        }

        $affiliate = $this->userRepository->findOneBy(['affiliateCode' => $code]);
        if ($affiliate === null || $affiliate === $newUser) {
            return;
        }

        $newUser->setReferredBy($affiliate);
        $this->clearSessionCode();
    }

    // ── Criação de comissão ───────────────────────────────────────────────────

    /**
     * Deve ser chamado quando um pedido é confirmado/pago.
     * Retorna a comissão criada, ou null se não há afiliado.
     */
    public function createCommission(Order $order): ?AffiliateCommission
    {
        $customer  = $order->getUser();
        $affiliate = $customer->getReferredBy();

        if ($affiliate === null) {
            return null;
        }

        // Evita duplicata por pedido
        $existing = $this->em->getRepository(AffiliateCommission::class)
            ->findOneBy(['order' => $order]);
        if ($existing !== null) {
            return $existing;
        }

        $rate   = $affiliate->getEffectiveCommissionRate($this->defaultRate);
        $amount = round((float) $order->getTotal() * $rate, 2);

        if ($amount <= 0) {
            return null;
        }

        $commission = new AffiliateCommission();
        $commission->setAffiliate($affiliate);
        $commission->setOrder($order);
        $commission->setCustomer($customer);
        $commission->setAmount((string) $amount);
        $commission->setRate((string) $rate);
        $commission->setStatus(AffiliateCommission::STATUS_PENDING);

        $this->em->persist($commission);
        $this->em->flush();

        return $commission;
    }

    // ── Pagamento de comissão ─────────────────────────────────────────────────

    /**
     * Marca a comissão como paga e credita na carteira do afiliado.
     */
    public function payCommission(AffiliateCommission $commission): void
    {
        if ($commission->getStatus() !== AffiliateCommission::STATUS_PENDING) {
            return;
        }

        $commission->setStatus(AffiliateCommission::STATUS_PAID);
        $commission->setPaidAt(new \DateTimeImmutable());

        $this->walletService->credit(
            $commission->getAffiliate(),
            (float) $commission->getAmount(),
            sprintf('Comissão de afiliado — Pedido #%d', $commission->getOrder()->getId())
        );

        $this->em->flush();
    }

    public function getDefaultRate(): float
    {
        return $this->defaultRate;
    }
}
