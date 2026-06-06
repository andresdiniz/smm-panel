<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contrato único para todos os gateways de pagamento.
 * Implementações: AsaasGateway, PagBankGateway, StubPaymentGateway (dev).
 */
interface PaymentGatewayInterface
{
    /**
     * Cria uma cobrança de depósito de carteira.
     *
     * @param User   $user        Usuário pagador
     * @param int    $amountCents Valor em centavos (ex: 5000 = R$ 50,00)
     * @param string $method      'pix' | 'credit_card' | 'debit_card'
     *
     * @return Payment Entidade persistida com status PENDING
     */
    public function createDeposit(User $user, int $amountCents, string $method): Payment;

    /**
     * Processa o payload de webhook recebido do gateway.
     * Deve validar assinatura, atualizar status do Payment e
     * creditar Wallet quando aprovado.
     *
     * @return Payment Entidade atualizada
     * @throws \InvalidArgumentException Se o payload for inválido ou a assinatura falhar
     */
    public function processWebhook(Request $request): Payment;

    /**
     * Consulta o status atual de um pagamento diretamente na API do gateway.
     * Útil para reconciliação de webhooks perdidos.
     */
    public function fetchStatus(Payment $payment): string;
}
