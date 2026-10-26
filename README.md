# SMM Panel — Symfony 8.1 / PHP 8.5

Painel SMM moderno com carteira em BRL, API compatível com **SMM Panel API v2**, integração completa de Pix via **Asaas** e webhook seguro.

## Stack

- PHP 8.5
- Symfony 8.1
- Doctrine ORM 3
- MySQL 8
- Twig + jQuery
- Symfony HttpClient
- Docker / Nginx / PHP-FPM

## Instalação

```bash
cp .env.example .env
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:create-admin admin@example.com strong-password
symfony server:start
```

## Variáveis obrigatórias

- `APP_SECRET`
- `DATABASE_URL`
- `ASAAS_API_KEY`
- `ASAAS_WEBHOOK_TOKEN`
- `SMM_PROVIDER_URL`
- `SMM_PROVIDER_KEY`
- `SMM_API_TOKEN`

## Asaas Pix

1. Configure o webhook no Asaas para `https://SEU_DOMINIO/webhook/asaas`.
2. Defina o header/token `asaas-access-token` igual ao valor de `ASAAS_WEBHOOK_TOKEN`.
3. Habilite os eventos `PAYMENT_RECEIVED`, `PAYMENT_CONFIRMED` e `PAYMENT_REFUNDED`.

O endpoint valida o token, grava o evento para idempotência e credita a carteira somente quando o pagamento é confirmado.

## API SMM v2

Endpoint: `POST /api/v2`

Autenticação:

```http
Authorization: Bearer SEU_SMM_API_TOKEN
```

Ações suportadas:

- `services`
- `add`
- `status`
- `balance`

Exemplo:

```http
POST /api/v2
Content-Type: application/x-www-form-urlencoded
Authorization: Bearer SEU_TOKEN

action=add&service=1&link=https://instagram.com/user&quantity=1000
```

## Segurança

- Senhas com hash moderno via Symfony Security.
- CSRF em formulários.
- Sessão `HttpOnly` e `SameSite=Lax`.
- Webhook com comparação constante (`hash_equals`).
- Idempotência de webhooks.
- Rate limit na API.
- Transações financeiras atômicas.
- Nenhum segredo exposto ao frontend.
