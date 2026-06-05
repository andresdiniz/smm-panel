# SMM Panel

> Plataforma completa de Social Media Marketing com PHP 8.5 + Symfony 7.

## Stack

- **PHP 8.5** + **Symfony 7**
- **MySQL 8** / Doctrine ORM 3
- **Redis** — Symfony Messenger transports
- **Symfony Scheduler** — tarefas recorrentes
- **Symfony Mailer** — fila de e-mails
- **EasyAdmin 4** — painel administrativo / CRM
- **Asaas / PagBank** — Pix + Cartão de crédito/débito
- **Stripe** — fallback internacional

## Módulos

| Módulo | Descrição |
|---|---|
| `Auth` | Login, 2FA, RBAC por roles |
| `Catalog` | Categorias e serviços SMM |
| `Order` | Pedidos, status, providers externos |
| `Billing` | Pix, cartão, webhooks, conciliação |
| `Wallet` | Saldo do usuário, movimentações |
| `CRM` | Clientes, contatos, tickets, funil |
| `Finance` | Entradas, saídas, taxas, relatórios |
| `Notification` | E-mails transacionais, SMS (futuro) |
| `Scheduler` | Crons: sync status, relatórios, CRM |

## Quick Start

```bash
cp .env .env.local        # Ajuste as variáveis
make up                   # Sobe MySQL + Redis + Mailpit
make install              # composer install
make migrate              # Roda migrations
make fixtures             # (opcional) dados de exemplo
make workers              # Inicia workers Messenger
php bin/console server:start
```

## Workers (Supervisor)

```bash
sudo cp deploy/supervisor/smm-workers.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

## Comandos úteis

```bash
# Pedidos com status QUEUED sem atualização há 30min
php bin/console smm:orders:sync-stale

# Conciliar pagamentos pendentes de webhook
php bin/console smm:billing:reconcile

# Relatório financeiro diário
php bin/console smm:finance:daily-report

# Ver fila de mortos
php bin/console messenger:failed:show
```
