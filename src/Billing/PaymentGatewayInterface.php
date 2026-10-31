<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

interface PaymentGatewayInterface
{
    /**
     * Cria uma cobrança de depósito.
     *
     * @param string $cpf   CPF somente dígitos — vazio usa fallback do gateway
     * @param string $phone Telefone com DDI +55 (ex: "+5511999990000") — vazio omite
     */
    public function createDeposit(
        User   $user,
        int    $amountCents,
        string $method,
        string $cpf   = '',
        string $phone = '',
    ): Payment;

    public function processWebhook(Request $request): Payment;

    public function fetchStatus(Payment $payment): string;
}
