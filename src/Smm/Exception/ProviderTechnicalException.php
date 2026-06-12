<?php

declare(strict_types=1);

namespace App\Smm\Exception;

/**
 * Falha técnica ao se comunicar com o provider (timeout, HTTP 5xx, resposta
 * malformada, etc.).
 *
 * Esses erros PODEM ser recuperáveis — o pedido vai para STATUS_ERROR e cai
 * no transport `failed` para retry manual ou automático.
 */
class ProviderTechnicalException extends ProviderApiException {}
