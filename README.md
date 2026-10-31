# SMM Panel

Painel SMM construído com **PHP 8.4+** e **Symfony 8.1**.

## Requisitos

- PHP 8.4 ou superior
- Composer 2
- MySQL 8+
- Extensões: `pdo_mysql`, `ctype`, `iconv`

## Instalação

```bash
git clone https://github.com/andresdiniz/smm-panel.git
cd smm-panel
composer install
cp .env.local.example .env.local
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
symfony server:start
```

## Docker

```bash
docker compose up -d
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

A aplicação fica disponível em `http://localhost:8080`.

## Filas e tarefas agendadas

```bash
php bin/console messenger:consume async -vv
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```

## Atualização para Symfony 8.1

Depois de atualizar o branch:

```bash
composer update --with-all-dependencies
php bin/console cache:clear
php bin/console doctrine:migrations:migrate
php bin/console lint:container
php bin/console lint:twig templates
php bin/console debug:router
```

## Produção

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
```

Use HTTPS, secrets fora do repositório e um worker persistente para o Messenger.
