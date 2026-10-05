.PHONY: setup up down logs test test-api test-web test-concurrency test-db migrate seed lint gen-api loadtest simulate-approve admin

# 1) cria api/.env (com a APP_KEY de DEV do .env.example) e web/.env. Não sobrescreve se já existirem.
setup:
	@cp -n api/.env.example api/.env || true
	@cp -n web/.env.example web/.env || true
	@echo "OK: api/.env e web/.env prontos. Agora: make up"

# 2) sobe tudo (build inclui as dependências de dev: Pest, Pint)
up:
	docker compose up --build -d
	@echo "Web:     http://localhost:5173   (admin: admin@codificar.dev / password)"
	@echo "API:     http://localhost:8000/api/v1/events"
	@echo "Mailpit: http://localhost:8025"

down:
	docker compose down -v

logs:
	docker compose logs -f --tail=100

migrate:
	docker compose exec api php artisan migrate --force

seed:
	docker compose exec api php artisan db:seed --force

# Para volumes antigos onde o init script não rodou
test-db:
	@docker compose exec -T postgres psql -U ticketing -d postgres -tc "SELECT 1 FROM pg_database WHERE datname='ticketing_test'" | grep -q 1 \
	  || docker compose exec -T postgres psql -U ticketing -d postgres -c "CREATE DATABASE ticketing_test OWNER ticketing"

test: test-api test-web

test-api: test-db
	docker compose exec api php vendor/bin/pest

test-concurrency: test-db
	docker compose exec api php vendor/bin/pest tests/Concurrency

test-web:
	docker compose exec web npm test -- --run
	docker compose exec web npm run typecheck

lint:
	docker compose exec api php vendor/bin/pint --test

gen-api:
	docker compose exec web npm run gen:api

admin:
	docker compose exec api php artisan admin:create $(EMAIL) --name="$(NAME)" --password=$(PASSWORD)

simulate-approve:
	docker compose exec api php artisan gateway:approve $(ORDER_ID)

# Teste de carga: compras simultâneas + ~30 pollers do painel. O rate limit por IP é desligado.
loadtest:
	docker compose exec -e ORDERS_RATE_LIMIT_PER_MINUTE=0 api php artisan loadtest:storm --buyers=500 --concurrency=100 --pollers=30
