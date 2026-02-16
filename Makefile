start:
	php artisan serve --host localhost

start-frontend:
	npm run dev

install: setup

setup:
	composer install
	cp -n .env.example .env
	php artisan key:gen --ansi
	touch database/database.sqlite
	php artisan migrate
	php artisan db:seed
	npm ci
	npm run build
	make ide-helper

watch:
	npm run watch

migrate:
	php artisan migrate

console:
	php artisan tinker

log:
	tail -f storage/logs/laravel.log

test:
	php artisan test

test-coverage:
	XDEBUG_MODE=coverage php artisan test --coverage-html=coverage --coverage-clover=build/logs/clover.xml

deploy:
	git push heroku

lint:
	composer exec -v phpcs

lint-fix:
	composer exec phpcbf

ide-helper:
	php artisan ide-helper:eloquent
	php artisan ide-helper:gen
	php artisan ide-helper:meta
	php artisan ide-helper:mod -n

dump: # перестройка автозагрузчика Composer
	composer dump-autoload
