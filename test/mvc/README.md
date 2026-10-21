# MVC core tests

Run from the parent directory (where `docker-compose.yml` lives):

- Unit suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-unit.xml`
- Integration suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-integration.xml`
- HTTP smoke test: `bash dolibarr/test/mvc/smoke.sh http://127.0.0.1:8480 admin admin` (only PASS lines expected, stops at the first FAIL)
- Static analysis: `docker compose exec -T php-fpm phpstan analyse -c dolibarr/phpstan-mvc.neon --memory-limit=1G` (needs 1G of memory)

The route cache lives in `DOL_DATA_ROOT/cache/routes.php`: delete it on every deploy in prod mode.

## Apache

An Apache 2.4 service (`httpd:2.4` + `mod_proxy_fcgi` to the same `php-fpm`) is available as an alternative to nginx, on port 8481:

- Start: `docker compose --profile apache up -d --build apache`
- Smoke: `bash dolibarr/test/mvc/smoke.sh http://127.0.0.1:8481 admin admin`

`htdocs/.htaccess` reproduces the nginx rules with `mod_rewrite`: `/src/` is forbidden (403); a missing static asset is a plain 404 (no PHP); an existing file is served as is; a directory with an `index.php` runs it; anything else (including a missing `.php`) goes to `app.php` with `REQUEST_URI` preserved. `DirectorySlash Off` lets `/user` (a directory without `index.php`, but an MVC route) reach `app.php` instead of mod_dir's 301. The virtual host needs `AllowOverride All` (or FileInfo+Indexes) and `AcceptPathInfo On` (REST API `api/index.php/explorer/`). Without `.htaccess` support, use the inline rules of `dev/examples/apache/dolibarr-vhost.conf`.

Dolibarr in a subdirectory (`DOL_URL_ROOT` not empty): uncomment and adapt `RewriteBase` in `.htaccess`.

Caveat: `$dolibarr_main_url_root` in the test `conf.php` is `http://127.0.0.1:8480`, so some absolute links built by Dolibarr point to port 8480 when browsing the Apache instance on 8481. The smoke test compares paths/suffixes only, so it is unaffected.
