# ================================================================
#  designstoun — команды управления проектом
# ================================================================

.PHONY: setup install start start-frontend start-queue db migrate seed \
        watch console log test test-pg test-fast test-js test-coverage lint lint-fix stan \
        ide-helper dump deploy help

# ----------------------------------------------------------------
# Установка проекта (первый запуск)
# ----------------------------------------------------------------
install: setup

setup:
	@echo ""
	@echo "╔══════════════════════════════════════════════════╗"
	@echo "║        Установка designstoun_2026                ║"
	@echo "╚══════════════════════════════════════════════════╝"
	@echo ""

	@# 1. PHP-зависимости
	@echo "→ [1/8] Устанавливаем PHP-зависимости..."
	composer install --no-interaction --prefer-dist

	@# 2. .env
	@echo "→ [2/8] Настраиваем .env..."
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "   Файл .env создан из .env.example"; \
		echo "   ⚠️  Заполните параметры БД и MOYSKLAD_TOKEN в .env перед продолжением!"; \
		echo ""; \
		echo "   Обязательные параметры:"; \
		echo "     DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD"; \
		echo "     MOYSKLAD_TOKEN"; \
		echo "     DEFAULT_STORE_ID"; \
		echo ""; \
		read -p "   Нажмите Enter когда .env будет заполнен..."; \
	else \
		echo "   .env уже существует — пропускаем"; \
	fi

	@# 3. APP_KEY
	@echo "→ [3/8] Генерируем APP_KEY..."
	php artisan key:generate --ansi --force

	@# 4. Проверка подключения к БД
	@echo "→ [4/8] Проверяем подключение к PostgreSQL..."
	php artisan db:show --no-interaction 2>/dev/null || \
		(echo "   ❌ Не удалось подключиться к БД. Проверьте параметры в .env" && exit 1)

	@# 5. Миграции
	@echo "→ [5/8] Запускаем миграции..."
	php artisan migrate --no-interaction --force

	@# 6. Seed
	@echo "→ [6/8] Заполняем начальные данные..."
	php artisan db:seed --no-interaction --force

	@# 7. Права на storage и bootstrap/cache
	@echo "→ [7/8] Настраиваем права на папки..."
	chmod -R 775 storage bootstrap/cache
	php artisan storage:link --force 2>/dev/null || true

	@# 8. Frontend
	@echo "→ [8/8] Собираем фронтенд..."
	npm ci --silent
	npm run build

	@echo ""
	@echo "╔══════════════════════════════════════════════════╗"
	@echo "║  ✅  Установка завершена!                        ║"
	@echo "║                                                  ║"
	@echo "║  Вход в систему:                                 ║"
	@echo "║    Телефон:  ADMIN_PHONE из .env (89123456789)   ║"
	@echo "║    Пароль:   ADMIN_PASSWORD из .env (12345678)   ║"
	@echo "║                                                  ║"
	@echo "║  ⚠️  Смените пароль после первого входа!         ║"
	@echo "╚══════════════════════════════════════════════════╝"
	@echo ""
	@echo "  Запуск:  make start"
	@echo ""

# ----------------------------------------------------------------
# Повторный сид (без пересоздания БД)
# ----------------------------------------------------------------
seed:
	php artisan db:seed --no-interaction --force

# ----------------------------------------------------------------
# Сброс и пересоздание БД (осторожно — удаляет все данные!)
# ----------------------------------------------------------------
fresh:
	@echo "⚠️  Это удалит ВСЕ данные в БД. Продолжить? [y/N]" && \
		read ans && [ "$${ans:-N}" = "y" ] || exit 0
	php artisan migrate:fresh --seed --force

# ----------------------------------------------------------------
# Разработка
# ----------------------------------------------------------------
start:
	php artisan serve

start-frontend:
	npm run dev

start-queue:
	php artisan queue:work --timeout=300

watch:
	npm run watch

# ----------------------------------------------------------------
# БД
# ----------------------------------------------------------------
db:
	sudo service postgresql start

migrate:
	php artisan migrate

migrate-status:
	php artisan migrate:status

rollback:
	php artisan migrate:rollback

# ----------------------------------------------------------------
# Утилиты
# ----------------------------------------------------------------
console:
	php artisan tinker

log:
	tail -f storage/logs/laravel.log

cache-clear:
	php artisan config:clear
	php artisan cache:clear
	php artisan route:clear
	php artisan view:clear
	@echo "✅ Кэш очищен"

cache-warm:
	php artisan config:cache
	php artisan route:cache
	php artisan view:cache
	@echo "✅ Кэш прогрет"

# ----------------------------------------------------------------
# Тесты
# ----------------------------------------------------------------
# PostgreSQL, как на проде: БД designstoun_test (phpunit.xml), хост и пользователь — из .env.
# Без --parallel: у пользователя БД нет CREATEDB для копий базы на процесс
test:
	php artisan test

# Прежнее имя — то же, что make test
test-pg: test

# Быстрая проверка по ходу работы на SQLite in-memory. Ошибки типов PG не ловит —
# перед коммитом make test
test-fast:
	DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --parallel

# JS-тесты (Vitest, tests/js): превью формул по общей фикстуре с Pest
test-js:
	npm test

test-coverage:
	XDEBUG_MODE=coverage php artisan test --coverage-html coverage-report

# ----------------------------------------------------------------
# Качество кода
# ----------------------------------------------------------------
lint:
	vendor/bin/pint --test

lint-fix:
	vendor/bin/pint

stan:
	vendor/bin/phpstan analyse --memory-limit=1G

dump:
	composer dump-autoload

ide-helper:
	php artisan ide-helper:eloquent
	php artisan ide-helper:gen
	php artisan ide-helper:meta
	php artisan ide-helper:mod -n

# ----------------------------------------------------------------
# Деплой
# ----------------------------------------------------------------
deploy:
	git push heroku

# ----------------------------------------------------------------
# Помощь
# ----------------------------------------------------------------
help:
	@echo ""
	@echo "  Доступные команды:"
	@echo ""
	@echo "  make setup          — первичная установка проекта"
	@echo "  make fresh          — пересоздать БД (удалит данные!)"
	@echo "  make seed           — повторный запуск сидов"
	@echo "  make start          — запустить сервер"
	@echo "  make start-frontend — запустить Vite dev"
	@echo "  make start-queue    — запустить воркер очереди (отправка в МойСклад)"
	@echo "  make migrate        — применить новые миграции"
	@echo "  make migrate-status — статус миграций"
	@echo "  make rollback       — откатить последнюю миграцию"
	@echo "  make cache-clear    — очистить весь кэш Laravel"
	@echo "  make cache-warm     — прогреть кэш для продакшена"
	@echo "  make log            — следить за логами"
	@echo "  make console        — открыть tinker"
	@echo "  make test           — тесты на PostgreSQL (БД designstoun_test)"
	@echo "  make test-fast      — быстрые тесты на SQLite (перед коммитом — make test)"
	@echo "  make test-js        — JS-тесты (Vitest)"
	@echo "  make lint           — проверка стиля (Pint)"
	@echo "  make lint-fix       — исправить стиль (Pint)"
	@echo "  make stan           — статический анализ (larastan)"
	@echo "  make dump           — composer dump-autoload"
	@echo ""
