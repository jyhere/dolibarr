# MVC core tests

Run from the parent directory (where `docker-compose.yml` lives):

- Unit suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-unit.xml`
- Integration suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-integration.xml`
- HTTP smoke test: `bash dolibarr/test/mvc/smoke.sh http://127.0.0.1:8480 admin admin` (only PASS lines expected, stops at the first FAIL)
- Static analysis: `docker compose exec -T php-fpm phpstan analyse -c dolibarr/phpstan-mvc.neon --memory-limit=1G` (needs 1G of memory)

The route cache lives in `DOL_DATA_ROOT/cache/routes.php`: delete it on every deploy in prod mode.
