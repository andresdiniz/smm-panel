<?php

declare(strict_types=1);

namespace App\Smm\Exception;

/**
 * Erro de negócio retornado pelo provider (ex: link inválido, link duplicado,
 * quantidade fora do range, serviço inativo).
 *
 * Esses erros NÃO devem gerar retry — o pedido deve ser cancelado com reembolso
 * e o usuário notificado para corrigir os dados.
 */
class ProviderBusinessException extends ProviderApiException {}
