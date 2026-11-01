# SMM Panel

> Plataforma completa de Social Media Marketing construída com **PHP 8.5.12+ + Symfony 8**.
> Pedidos SMM, carteira, pagamentos (Pix/Cartão), CRM, afiliados e blog — tudo em uma stack.

---

## Stack

| Camada | Tecnologia |
|---|---|
| **Runtime** | PHP 8.5.12 ou superior |
| **Framework** | Symfony 8 (HttpKernel, Security, Messenger, Scheduler, Mailer) |
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
# 1. Configure DATABASE_URL, REDIS_URL e MAILER_DSN em .env.local.
#    Preserve qualquer configuração local já existente.

# 2. Subir serviços
docker compose up -d mysql redis mailpit

# 3. Instalar dependências
composer install

# 4. Banco de dados
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load --no-interaction  # opcional

# 5. Workers Messenger
# Execute estes dois processos continuamente (Supervisor/systemd ou processo gerenciado pelo hosting).
php bin/console messenger:consume scheduler_main --time-limit=3600 --memory-limit=256M -vv
php bin/console messenger:consume orders_high orders_medium orders_low --time-limit=3600 --memory-limit=256M -vv

# 6. Servidor de desenvolvimento
php -S 127.0.0.1:8000 -t public public/index.php
```

Acesse o admin em `http://localhost:8000/admin` com um usuário `ROLE_ADMIN`.

### Sincronização automática via Messenger

- `SyncServicesMessage` é agendada a cada 6 horas e roteada para `orders_low`;
  atualiza os limites min/max dos serviços ativos a partir do catálogo do provider.
  Cada execução e tentativa é persistida em `cron_logs`, visível em
  **Admin > Logs de tarefas agendadas**, com quantidade processada, quantidade
  atualizada, providers e erros. Falhas específicas do provider são reenfileiradas
  após 30 s, 2 min e 5 min; após quatro tentativas no total ficam visíveis como
  falha no admin.
- A cada 5 minutos, o agendador localiza pedidos em processamento sem log de
  status recente (10 minutos) e enfileira `SyncOrderStatusMessage` em
  `orders_medium`. O handler consulta o provider, registra cada tentativa,
  aplica conclusão/cancelamento e agenda o próximo polling com backoff. Pedidos
  recentes continuam usando o polling inicial e os retries já existentes.
  O worker que consome `orders_medium` precisa permanecer ativo para aplicar os
  status e os estornos; o comando direto `app:sync-order-status --order=ID`
  também atualiza o status, registra o log e processa o estorno por cancelamento.
- Para iniciar uma sincronização de serviços manualmente pela fila:

  ```bash
  php bin/console smm:services:sync-async
  php bin/console smm:services:sync-async --provider=slug-do-provider
  ```

- Confira as filas/retries com `php bin/console messenger:stats` e falhas com
  `php bin/console messenger:failed:show`. Reprocesse somente após corrigir a
  causa com `php bin/console messenger:failed:retry`.
- Em produção, mantenha os dois workers acima sempre ativos e reinicie-os após
  deploy; sem um worker de `scheduler_*` não há agendamento, e sem um worker de
  `orders_*` as mensagens ficam acumuladas sem executar.

### Login e recuperação de senha

O login limita tentativas a 5 por usuário/IP a cada 15 minutos. A solicitação
de recuperação limita 5 tentativas por IP e 3 por e-mail por hora; o envio de
nova senha limita 10 tentativas por IP por hora. Os links são de uso único e
expiram após 1 hora. Configure `MAILER_DSN` para entregar os e-mails e aplique
as migrations pendentes para criar a tabela de tokens de recuperação.
No ambiente local, o `.env` usa Mailpit em `127.0.0.1:1025`. Para envio real,
configure a credencial SMTP em `.env.local` com uma senha de app:
`MAILER_DSN=smtps://USUARIO:SENHA_URL_ENCODED@smtp.hostinger.com:465`.
A porta 465 exige TLS implícito (`smtps://`); não coloque credenciais no `.env`
rastreado pelo Git.

---

## Deploy em Produção (Shared Hosting / VPS)

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
```

### Agendamento principal e workers (Shared Hosting / VPS)

Todas as rotinas recorrentes implementadas são registradas em `main`. O Scheduler
avalia o calendário continuamente; cada rotina mantém sua própria frequência:
heartbeat a cada minuto, reconciliação de pagamentos e recuperação de pedidos a
cada 5 minutos, sincronização de serviços a cada 6 horas e relatórios diários.
As tarefas de serviços/pedidos são encaminhadas às filas Messenger apropriadas.

No VPS, mantenha `scheduler_main` ativo pelo Supervisor. No Shared Hosting, o
Cron Jobs do hPanel deve iniciar o consumidor principal uma vez por minuto,
substituindo pelo caminho absoluto real do projeto:

```cron
* * * * * /bin/bash /caminho/absoluto/do/projeto/cron-main.sh
```

Remova os antigos Cron Jobs de `cron-default.sh` e `cron-smm.sh` ao migrar, para
manter apenas um consumidor do schedule. O script atualiza
`var/log/scheduler_main.heartbeat` e registra saída em
`var/log/scheduler_main.log`. O consumidor de `orders_high`, `orders_medium` e
`orders_low` também precisa permanecer ativo para processar mensagens; prefira
Supervisor/systemd ou um worker gerenciado pelo hosting. Não agende
`cron-orders.sh` a cada minuto: ele consome as três filas em sequência e pode
ultrapassar o intervalo, criando processos concorrentes.

As rotinas de sincronização de saldo de providers, remarketing e limpeza de
tokens ainda não têm handlers executáveis no projeto e, portanto, não são
agendadas até que suas implementações estejam prontas.

### Workers (Supervisor)

```bash
sudo cp deploy/supervisor/smm-workers.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start smm:*
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

# Asaas (produção por padrão; use sandbox somente para homologação)
ASAAS_ENV=production

# As chaves dos gateways e tokens de webhook são gerenciados em
# Admin > Provedores / Chaves de API. Não os inclua no repositório.
# Use a chave da conta e o token de webhook do mesmo ambiente. Na Hostinger,
# configure ASAAS_ENV=production nas variáveis de ambiente da aplicação.

# Providers SMM compatíveis com o padrão SMM API v2 podem ser cadastrados em
# Admin > Provedores / Chaves de API, usando uma URL HTTPS pública.

# PagBank
PAGBANK_TOKEN=

# Stripe
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

# Afiliados
AFFILIATE_DEFAULT_RATE=0.05
```

### Webhook de pagamentos Asaas

1. Aplique as migrations antes de ativar o webhook:

   ```bash
   php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
   php bin/console doctrine:migrations:migrate --no-interaction
   ```

   Sincronizar o armazenamento de metadados também é necessário quando o
   Doctrine informar que ele está desatualizado. A migration cria `cron_logs`,
   tabela usada pela página de histórico de tarefas.

2. Em **Admin > Provedores / Chaves de API**, edite o gateway `asaas`. Mantenha a
   chave de API do ambiente correto e defina em **Token Secreto** um token
   aleatório ASCII de 32 a 255 caracteres, sem espaços. Esse token é diferente
   da chave de API e fica armazenado no banco; não o publique em logs ou no Git.
3. Na conta Asaas do mesmo ambiente, cadastre o webhook com:

   | Campo | Valor |
   |---|---|
   | URL | `https://acheireviews.com.br/webhook/asaas` |
   | Versão da API | `3` |
   | Token de autenticação | O mesmo valor salvo em **Token Secreto** |
   | Ativo / interrompido | Ativo / Não interrompido |
   | Tipo de envio | Sequencial (`SEQUENTIALLY`) |

   Selecione `PAYMENT_RECEIVED`, `PAYMENT_DELETED`,
   `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED` e
   `PAYMENT_REPROVED_BY_RISK_ANALYSIS`. A carteira só recebe crédito em
   `PAYMENT_RECEIVED`; `PAYMENT_CONFIRMED` ainda não significa saldo disponível
   no Asaas. Eventos são gravados por ID único e duplicatas não são processadas
   novamente.

O endpoint autentica o header `asaas-access-token`. A falha HTTP 401 de `GET
/customers` é separada do webhook: indica que a **chave de API** usada pela
aplicação foi recusada pelo Asaas. Confira a chave e se `ASAAS_ENV` corresponde
ao ambiente de origem dela. Documentação oficial: [receber eventos](https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook.md),
[criar webhook pela API](https://docs.asaas.com/docs/criar-novo-webhook-pela-api.md)
e [tipos de envio](https://docs.asaas.com/docs/tipos-de-envio.md).

Quando uma cobrança Asaas é criada mas o QR Code demora a ficar disponível, a
cobrança permanece vinculada à carteira e o modal permite tentar buscar o QR
novamente. Os logs registram a etapa e o status HTTP sem armazenar CPF, telefone
ou credenciais do gateway. Em produção, esses avisos do canal `payment` são
enviados diretamente para `stderr` para não serem descartados pelo handler
`fingers_crossed`.
