.PHONY: install up down migrate fixtures test cc workers

install:
	composer install
	npm ci

up:
	docker-compose up -d

down:
	docker-compose down

migrate:
	php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	php bin/console doctrine:fixtures:load --no-interaction

test:
	php bin/phpunit

cc:
	php bin/console cache:clear

workers:
	php bin/console messenger:consume orders_high orders_medium orders_low --time-limit=3600 --memory-limit=256M -vv

workers-failed:
	php bin/console messenger:failed:show
