# MVC core tests

Run from the parent directory (where `docker-compose.yml` lives):

- Unit suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-unit.xml`
- Integration suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-integration.xml`
- HTTP smoke test: `bash dolibarr/test/mvc/smoke.sh http://127.0.0.1:8480 admin admin` (only PASS lines expected, stops at the first FAIL)
- Static analysis: `docker compose exec -T php-fpm phpstan analyse -c dolibarr/phpstan-mvc.neon --memory-limit=1G` (needs 1G of memory)

The route cache lives in `DOL_DATA_ROOT/cache/routes.php` and the legacy redirect table in `DOL_DATA_ROOT/cache/legacy_redirects.php`: delete both on every deploy in prod mode (see below).

## Naming convention

Variables, parameters and properties use descriptive names, in PHP code and in Latte templates alike: `$response`, `$user`, `$form`, never `$r`, `$u`, `$f`. Only two exceptions are allowed: `$i`/`$j` as loop counters, and `$e` for the exception caught by a `catch`. The unit test `ReadableNamesTest` enforces the rule on `htdocs/src` and `test/phpunit/mvc`.

## Apache

An Apache 2.4 service (`httpd:2.4` + `mod_proxy_fcgi` to the same `php-fpm`) is available as an alternative to nginx, on port 8481:

- Start: `docker compose --profile apache up -d --build apache`
- Smoke: `bash dolibarr/test/mvc/smoke.sh http://127.0.0.1:8481 admin admin`

`htdocs/.htaccess.example` reproduces the nginx rules with `mod_rewrite`: `/src/` is forbidden (403); a missing static asset is a plain 404 (no PHP); an existing file is served as is; a directory with an `index.php` runs it; anything else (including a missing `.php`) goes to `app.php` with `REQUEST_URI` preserved. `DirectorySlash Off` lets `/user` (a directory without `index.php`, but an MVC route) reach `app.php` instead of mod_dir's 301.

The file is inactive as shipped (an active `.htaccess` would change the behaviour of every existing Apache install on upgrade). To enable it, either copy it to `htdocs/.htaccess`, which needs `AllowOverride All` (or `AllowOverride FileInfo Indexes Options`: FileInfo for the rewrite rules, Indexes for `DirectoryIndex`/`DirectorySlash`, Options for `Options -Indexes`), or put the rules in the vhost, as in `dev/examples/apache/dolibarr-vhost.conf` (no override needed). The vhost also needs `AcceptPathInfo On` (REST API `api/index.php/explorer/`). Without the rules, MVC URLs return 404 and `/src/` is not blocked by a rule.

The test container (`docker/apache/vhost.conf` in the parent directory) `Include`s `htdocs/.htaccess.example` in its `<Directory>` with `AllowOverride None`: the example file itself is what the Apache smoke tests (the source volume is read-only, so it is not copied).

Dolibarr in a subdirectory (`DOL_URL_ROOT` not empty, e.g. `/dolibarr`): uncomment and adapt `RewriteBase` (the substitutions are relative, so they follow it). Checked with an `Alias /dolibarr` vhost: `/dolibarr/`, `/dolibarr/admin/` run their `index.php`, `/dolibarr/src/` is 403, unknown paths reach `app.php`.

Caveat: `$dolibarr_main_url_root` in the test `conf.php` is `http://127.0.0.1:8480`, so some absolute links built by Dolibarr point to port 8480 when browsing the Apache instance on 8481. The smoke test compares paths/suffixes only, so it is unaffected.

## Legacy URL redirects

Old pages of a rewritten module redirect (302, GET only) to their MVC route, e.g. `user/card.php?id=1` goes to `/user/1`.

How it works: each module declares `legacyRedirects()` (a list of `LegacyRedirect` rules: script, route name, parameter mapping, required parameters, handled `action` values, forbidden parameters, parameters to carry). The rules of all modules are compiled into `DOL_DATA_ROOT/cache/legacy_redirects.php`. A block at the top of `htdocs/main.inc.php` (`src/legacy_redirect.inc.php`) looks the request up before anything else runs. Any failure leaves the legacy page to run.

Redirects start after the first page served by `app.php`: a matched route creates the empty marker `DOL_DATA_ROOT/cache/mvc_front_ok`, and no legacy page is redirected while it is missing. This requires the nginx `try_files` rule or the Apache rules (see above): without them the routes would answer 404, `app.php` never serves a route and the legacy pages keep working. Delete the marker if the rewrite is removed afterwards.

Every rule also carries the display and context parameters read by `main.inc.php` (`LegacyRedirector::GLOBAL_CARRY`: `dol_hide_topmenu`, `dol_hide_leftmenu`, `dol_optimize_smallscreen`, `dol_no_mouse_hover`, `dol_use_jmobile`, `optioncss`, `lang`, `mainmenu`, `leftmenu`).

It does NOT redirect: a POST (or any non-GET), an AJAX request, a request served by `app.php` (`DOL_MVC_FRONT`), the CLI, PHP < 8.2, a page that defines `NOREDIRECTLEGACY`, `id=0` (the `id` of `card.php`, `note.php`, `perms.php` must match `[1-9]\d*`), and any URL whose `action` or parameters no rule covers (the legacy page answers, e.g. `card.php?action=clone`, `card.php` without `id`, `list.php` with `massaction`, a multi-select category filter, extrafield filters or `contextpage=employeelist`).

Switches:

- `$dolibarr_main_mvc_legacy_redirects = '0';` in `conf.php` (wins over the constant);
- constant `MAIN_MVC_LEGACY_REDIRECTS` = `0`; read on every request, enabled by default;
- `define('NOREDIRECTLEGACY', 1);` before including `main.inc.php`, for one page.

Declaring a rule, in the module class:

```php
public function legacyRedirects(): array
{
	return [
		// user/card.php?id=12 -> /user/12 (only when id is numeric and there is no action)
		new LegacyRedirect('user/card.php', 'user_show', ['id' => 'id'], required: ['id'], requirements: ['id' => '\d+']),
		// user/card.php?id=12&action=edit -> /user/12/edit
		new LegacyRedirect('user/card.php', 'user_edit', ['id' => 'id'], required: ['id'], actions: ['edit']),
	];
}
```

Deploy: the caches are stamped by Dolibarr version and module list, not by rule content. After deploying a change of rules in prod mode, delete `documents/cache/routes.php` and `documents/cache/legacy_redirects.php`.

Route constants: `#[Route(..., constants: ['EVEN_IF_ONLY_LOGIN_ALLOWED'])]` makes `app.php` define those constants (to `1`) before `main.inc.php` for that route, as the legacy page did. The `logout` route uses it: under `MAIN_ONLY_LOGIN_ALLOWED`, the offline message links to `user/logout.php`, redirected to `/logout`, which must still disconnect.

Covered URLs: `user/card.php` (`id`; `action=edit|disable|enable|delete|password|passwordsend`; `action=create`, with `employee`), `user/list.php` (plain filters and sort), `user/note.php`, `user/perms.php`, `user/logout.php`. `smoke.sh` asserts them on nginx and Apache, with the cases that must not redirect, the absence of loops, the switch and the logout under `MAIN_ONLY_LOGIN_ALLOWED` (set to a dummy login, then removed).

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
