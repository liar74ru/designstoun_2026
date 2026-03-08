# ================================================================
#  designstoun — команды управления проектом
# ================================================================

.PHONY: setup install start start-frontend db migrate seed \
        watch console log test test-coverage lint lint-fix \
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
	@echo "║    Телефон:  89128993488                         ║"
	@echo "║    Пароль:   12345678                            ║"
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
test:
	php artisan test

test-coverage:
	XDEBUG_MODE=coverage php artisan test --coverage-html coverage-report

# ----------------------------------------------------------------
# Качество кода
# ----------------------------------------------------------------
lint:
	composer exec -v phpcs

lint-fix:
	composer exec phpcbf

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
	@echo "  make migrate        — применить новые миграции"
	@echo "  make migrate-status — статус миграций"
	@echo "  make rollback       — откатить последнюю миграцию"
	@echo "  make cache-clear    — очистить весь кэш Laravel"
	@echo "  make cache-warm     — прогреть кэш для продакшена"
	@echo "  make log            — следить за логами"
	@echo "  make console        — открыть tinker"
	@echo "  make test           — запустить тесты"
	@echo "  make lint           — проверка кода"
	@echo "  make dump           — composer dump-autoload"
	@echo ""
