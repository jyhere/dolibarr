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

`htdocs/.htaccess.example` reproduces the nginx rules with `mod_rewrite`: `/src/` is forbidden (403); a missing static asset is a plain 404 (no PHP); an existing file is served as is; a directory with an `index.php` runs it; anything else (including a missing `.php`) goes to `app.php` with `REQUEST_URI` preserved. `DirectorySlash Off` lets `/user` (a directory without `index.php`, but an MVC route) reach `app.php` instead of mod_dir's 301.

The file is inactive as shipped (an active `.htaccess` would change the behaviour of every existing Apache install on upgrade). To enable it, either copy it to `htdocs/.htaccess`, which needs `AllowOverride All` (or `AllowOverride FileInfo Indexes Options`: FileInfo for the rewrite rules, Indexes for `DirectoryIndex`/`DirectorySlash`, Options for `Options -Indexes`), or put the rules in the vhost, as in `dev/examples/apache/dolibarr-vhost.conf` (no override needed). The vhost also needs `AcceptPathInfo On` (REST API `api/index.php/explorer/`). Without the rules, MVC URLs return 404 and `/src/` is not blocked by a rule.

The test container (`docker/apache/vhost.conf` in the parent directory) `Include`s `htdocs/.htaccess.example` in its `<Directory>` with `AllowOverride None`: the example file itself is what the Apache smoke tests (the source volume is read-only, so it is not copied).

Dolibarr in a subdirectory (`DOL_URL_ROOT` not empty, e.g. `/dolibarr`): uncomment and adapt `RewriteBase` (the substitutions are relative, so they follow it). Checked with an `Alias /dolibarr` vhost: `/dolibarr/`, `/dolibarr/admin/` run their `index.php`, `/dolibarr/src/` is 403, unknown paths reach `app.php`.

Caveat: `$dolibarr_main_url_root` in the test `conf.php` is `http://127.0.0.1:8480`, so some absolute links built by Dolibarr point to port 8480 when browsing the Apache instance on 8481. The smoke test compares paths/suffixes only, so it is unaffected.

## MVC routes available

| Method | Path | Legacy equivalent |
|---|---|---|
| GET | `/user` (list, filters, `.json`) | `user/list.php` |
| POST | `/user` (mass actions) | `user/list.php` |
| GET/POST | `/user/new` | `user/card.php?action=create` |
| GET | `/user/{id}` (`.json` too) | `user/card.php?id=` |
| GET/POST | `/user/{id}/edit` | `user/card.php?action=edit` |
| POST | `/user/{id}/status` | `card.php` `confirm_disable`/`confirm_enable` |
| GET/POST | `/user/{id}/password` | `card.php` `confirm_password` |
| POST | `/user/{id}/groups` | `card.php` `addgroup`/`removegroup` |
| POST | `/user/{id}/delete` | `card.php` `confirm_delete` |
| GET/POST | `/{entity}/{id}/note` (e.g. `/user/1/note`) | `user/note.php` |
| GET/POST | `/user/{id}/rights` | `user/perms.php` |
| GET/POST | `/login`, `/logout` | `index.php`, `user/logout.php` |

Every write is a POST with a CSRF token; an invalid token cancels the action and shows a warning. `smoke.sh` creates and removes its own `smoke_*` users and groups (it needs a SQL client: host `mysql` on port 33306, or `docker compose exec mysql`, or `SMOKE_MYSQL`).

Run all suites (from the parent directory): unit, integration, PHPStan and smoke on nginx (8480) and Apache (8481) with the commands above; all must be green.
