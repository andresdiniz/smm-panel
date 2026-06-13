# SMM Panel

> Plataforma completa de Social Media Marketing construída com **PHP 8.5 + Symfony 7**.
> Pedidos SMM, carteira, pagamentos (Pix/Cartão), CRM, afiliados e blog — tudo em uma stack.

---

## Stack

| Camada | Tecnologia |
|---|---|
| **Runtime** | PHP 8.5 |
| **Framework** | Symfony 7 (HttpKernel, Security, Messenger, Scheduler, Mailer) |
| **ORM** | Doctrine ORM 3 + Migrations |
| **Banco de dados** | MySQL 8 |
| **Cache / Filas** | Redis (Symfony Messenger transports) |
| **Admin** | EasyAdmin 4 |
| **Assets** | AssetMapper + Importmap (sem Node na produção) |
| **Pagamentos** | Asaas (Pix + Cartão) · PagBank · Stripe (fallback internacional) |
| **E-mail** | Symfony Mailer → Mailpit (dev) / SMTP (prod) |
| **Testes** | PHPUnit |

---

## Módulos

| Módulo | Entidades principais | Responsabilidade |
|---|---|---|
| **Auth** | `User` | Registro, login, RBAC (`ROLE_USER`, `ROLE_ADMIN`), hash de senha |
| **Catalog** | `Service`, `ServiceCategory` | Catálogo de serviços SMM com preço e quantidade |
| **Order** | `Order`, `OrderLog` | Pedidos, status de lifecycle, logs de provider |
| **Billing** | `Payment` | Pix, cartão, webhooks Asaas/PagBank, conciliação |
| **Wallet** | `Wallet`, `WalletTransaction` | Saldo do usuário, débito automático em pedidos |
| **CRM** | `CrmContact` | Perfil de marketing: tags, timeline de eventos, UTMs |
| **Affiliate** | `AffiliateCommission` | Links `/ref/{code}`, comissões pendente/pago/cancelado |
| **Support** | `SupportTicket`, `SupportMessage` | Tickets de suporte com thread de mensagens |
| **Blog** | `BlogPost`, `BlogCategory`, `BlogTag` | Blog público com categorias e tags |
| **Finance** | — | Relatórios de receita, saídas e taxas (via CRM dashboard) |
| **Scheduler** | `src/Schedule.php` | Crons: sync de pedidos, conciliação, relatório diário |
| **Notification** | — | E-mails transacionais via Symfony Mailer |

---

## CRM

O módulo CRM rastreia o ciclo de vida de marketing de cada usuário da plataforma.

### Entidade `CrmContact`

Cada usuário tem **um** `CrmContact` (relação `OneToOne`). Os campos principais são:

| Campo | Tipo | Descrição |
|---|---|---|
| `tags` | `json[]` | Labels livres (ex: `vip`, `reativação`, `abandono`) |
| `timeline` | `json[]` | Histórico imutável de eventos com payload e timestamp |
| `utmSource` | `string\|null` | Origem da aquisição (`google`, `instagram`, …) |
| `utmCampaign` | `string\|null` | Campanha de aquisição |
| `utmMedium` | `string\|null` | Mídia de aquisição (`cpc`, `email`, …) |

### Eventos registrados na timeline

```
order.placed     → pedido criado
order.completed  → pedido concluído
payment.paid     → pagamento confirmado
payment.refunded → reembolso processado
affiliate.click  → clique no link de afiliado
affiliate.joined → indicado se registrou
```

### Dashboard admin `/admin/crm`

O dashboard (`templates/admin/crm_dashboard.html.twig`) estende `@EasyAdmin/page/content.html.twig` e exibe:

- **5 KPIs**: receita do mês, saídas, taxas, total de contatos, ativos nos últimos 30 dias
- **Tabela de contatos recentes**: nome, e-mail, tags, UTM source/campaign, data de atualização

### Comandos CRM

```bash
# Sync de contatos inativos / atualização de tags automáticas
php bin/console smm:crm:sync
```

---

## Módulo de Afiliados

Sistema completo de indicação por link rastreável.

### Fluxo

```
Afiliado gera link → /ref/{code}
  └─ AffiliateController::click() → trackClick() → cookie + redirect /register
       └─ Novo usuário se registra → referredBy salvo no User
            └─ Usuário faz pedido → AffiliateCommission criada (status: pending)
                 └─ Admin aprova → status: paid → affiliateService.payout()
```

### Entidade `AffiliateCommission`

| Campo | Tipo | Descrição |
|---|---|---|
| `affiliate` | `User` | Quem recebe a comissão |
| `customer` | `User` | Quem fez o pedido |
| `order` | `Order` | Pedido que gerou a comissão |
| `amount` | `decimal(10,2)` | Valor em BRL |
| `rate` | `decimal(5,4)` | Taxa aplicada no momento (snapshot) |
| `status` | `pending\|paid\|cancelled` | Estado atual |
| `paidAt` | `datetime\|null` | Data do pagamento |

### Campos no `User`

```php
$user->getAffiliateCode();             // ex: "ab3f9x"
$user->getEffectiveCommissionRate();   // taxa personalizada ou AFFILIATE_DEFAULT_RATE
$user->getReferredUsers()->count();    // total de indicados
$user->getPendingCommissionAmount();   // saldo pendente
$user->getPaidCommissionAmount();      // total já pago
```

### Variável de ambiente

```dotenv
AFFILIATE_DEFAULT_RATE=0.05   # 5% por padrão — sobrescrito por taxa personalizada por usuário
```

### Rotas

| Rota | Controller | Acesso |
|---|---|---|
| `GET /ref/{code}` | `AffiliateController::click` | Público |
| `GET /painel/afiliado` | `AffiliateController::dashboard` | `ROLE_USER` |

### ⚠️ Ponto de atenção

O `AffiliateService` precisa de um `AffiliateCommissionRepository::findByAffiliate()` e `sumByStatus()` implementados. Verifique se a migration da tabela `affiliate_commission` foi executada:

```bash
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate
```

---

## Quick Start (Local)

```bash
# 1. Configurar ambiente
cp .env .env.local       # Ajuste DATABASE_URL, REDIS_URL, MAILER_DSN

# 2. Subir serviços
make up                  # MySQL 8 + Redis + Mailpit via Docker

# 3. Instalar dependências
make install             # composer install

# 4. Banco de dados
make migrate             # doctrine:migrations:migrate
make fixtures            # (opcional) dados de exemplo

# 5. Workers Messenger
make workers             # symfony messenger:consume async scheduler

# 6. Servidor de desenvolvimento
php bin/console server:start
```

Acesse o admin em `http://localhost:8000/admin` com um usuário `ROLE_ADMIN`.

---

## Deploy em Produção (Shared Hosting / VPS)

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
```

### Cron jobs (cPanel / crontab)

```cron
# Sync de pedidos pendentes (a cada 5 min)
*/5 * * * * /usr/bin/php /home/user/public_html/bin/console smm:orders:sync-stale >> /dev/null 2>&1

# Conciliação de pagamentos (a cada 10 min)
*/10 * * * * /usr/bin/php /home/user/public_html/bin/console smm:billing:reconcile >> /dev/null 2>&1

# Relatório financeiro diário (meia-noite)
0 0 * * * /usr/bin/php /home/user/public_html/bin/console smm:finance:daily-report >> /dev/null 2>&1
```

Ou use os scripts prontos em `cron-default.sh`, `cron-orders.sh` e `cron-smm.sh`.

### Workers (Supervisor)

```bash
sudo cp deploy/supervisor/smm-workers.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start smm-workers:*
```

---

## Comandos úteis

```bash
# Ver pedidos QUEUED sem atualização há 30min
php bin/console smm:orders:sync-stale

# Conciliar pagamentos pendentes de webhook
php bin/console smm:billing:reconcile

# Relatório financeiro diário
php bin/console smm:finance:daily-report

# Inspecionar fila de mortos
php bin/console messenger:failed:show

# Reprocessar mensagem falha
php bin/console messenger:failed:retry

# Verificar estado das migrations
php bin/console doctrine:migrations:status
```

---

## Estrutura de diretórios

```
src/
├── Billing/            # Gateways Asaas, PagBank, Stripe
├── Command/            # Comandos CLI (sync, reconcile, report)
├── Controller/
│   ├── Admin/          # Controllers EasyAdmin
│   ├── AffiliateController.php
│   ├── OrderController.php
│   ├── WalletController.php
│   └── ...
├── Entity/             # Doctrine entities
├── Enum/               # PHP 8.1+ enums de status
├── EventSubscriber/    # Listeners Symfony
├── Message/            # DTOs do Messenger
├── MessageHandler/     # Handlers async
├── Repository/         # Queries customizadas
├── Scheduler/          # Tasks do Symfony Scheduler
├── Security/           # Voters, authenticators
├── Service/            # Lógica de negócio (AffiliateService, etc.)
└── Smm/                # Integração com providers SMM externos
```

---

## Variáveis de ambiente relevantes

```dotenv
APP_ENV=prod
APP_SECRET=

DATABASE_URL="mysql://user:pass@127.0.0.1:3306/smm_panel?serverVersion=8.0"
REDIS_URL=redis://127.0.0.1:6379

MAILER_DSN=smtp://user:pass@smtp.host:587

# Asaas
ASAAS_API_KEY=
ASAAS_WEBHOOK_TOKEN=

# PagBank
PAGBANK_TOKEN=

# Stripe
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

# Afiliados
AFFILIATE_DEFAULT_RATE=0.05
```
