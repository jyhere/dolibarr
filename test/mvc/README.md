# MVC core tests

Run from the parent directory (where `docker-compose.yml` lives):

- Unit suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-unit.xml`
- Integration suite: `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-integration.xml`
- PostgreSQL suite (DB driver and ORM tests, second connection reserved to the tests; the website stays on MariaDB): first `docker compose up -d pgsql && bash docker/install-pgsql.sh` (separate install, data root `documents-pgsql`, conf `docker/php-fpm/conf.pgsql.php`, credentials `PGSQL_*` of `.env`), then `docker compose exec -T php-fpm phpunit -c dolibarr/test/phpunit/mvc/phpunit-pgsql.xml`
- HTTP smoke test: `bash dolibarr/test/mvc/smoke.sh http://127.0.0.1:8480 admin admin` (only PASS lines expected, stops at the first FAIL)
- Static analysis: `docker compose exec -T php-fpm phpstan analyse -c dolibarr/phpstan-mvc.neon --memory-limit=1G` (needs 1G of memory)

The route cache lives in `DOL_DATA_ROOT/cache/routes.php` and the legacy redirect table in `DOL_DATA_ROOT/cache/legacy_redirects.php`: delete both on every deploy in prod mode (see below).

## Naming convention

Variables, parameters and properties use descriptive names, in PHP code and in Latte templates alike: `$response`, `$user`, `$form`, never `$r`, `$u`, `$f`. Only two exceptions are allowed: `$i`/`$j` as loop counters, and `$e` for the exception caught by a `catch`. The unit test `ReadableNamesTest` enforces the rule on `htdocs/src` and `test/phpunit/mvc`.

Latte templates are indented with tabs, one level per open HTML element or Latte block (`{if}`, `{foreach}`, `{block}`, `{define}`); `{else}`/`{elseif}` sit at the level of their `{if}`. Template tests strip the leading indentation before asserting on the markup.

## Database and ORM

The MVC core runs bound SQL only, on the link opened by `DoliDB` (no second connection): `Dolibarr\Core\Database\Connection` picks a native driver from `$db->type` (`MysqliDriver`: mysqli prepared statements; `PgsqlDriver`: `pg_query_params`; `LegacyDriver`: escaped interpolation through `DoliDB`, for any other engine). The drivers keep what `DoliDB::query()` does: read-only mode (`$dolibarr_main_db_readonly`, code `APPREADONLY`), `sql=` debug log, `lastquery`/`lasterror` (the SQL with its placeholders, never the values). The old concatenation API (`legacyFetchAllObjects()`, `legacyFetchOneObject()`, `legacyExecute()`, `escape()`) is gone; `NoConcatenatedSqlTest` keeps it, `natural_search()`, `dol_natural_search_phone()`, `->escape()` and any `new mysqli`/`new PDO`/`pg_connect()` out of `htdocs/src` (only `LegacyDriver` calls `DoliDB::escape()`).

`Connection` API:

- `fetchAll($sql, $params)` (list of rows as arrays), `fetchOne()` (first row or null), `fetchValue()` (first column of the first row or null), `execute()` (affected rows), `lastInsertId('user')` (table **without** prefix).
- SQL: `@user` is the prefixed table, `:name` a parameter (several uses allowed); an array gives `IN (:ids)` (an empty array is bound as `NULL`: `IN (NULL)` is a false condition, not invalid SQL, but `NOT IN (NULL)` is false too, so it returns no row; for `NOT IN` with a possibly empty list, use `Query::whereNotIn()` (an empty list gives `1 = 1`) or test the empty case); bool → 0/1, backed enum → its value, `DateTimeInterface` → `Y-m-d H:i:s` without time zone conversion. A positional `?` is refused.
- `transactional(fn () => ...)`: BEGIN/COMMIT through the `DoliDB` nesting counter, so inside the transaction the legacy code opened (an inner failure only lowers the counter: nothing is undone until the outermost level rolls back). Any exception rolls back and is rethrown; a failed ROLLBACK throws `DatabaseException` with the work's exception as previous. `begin()`/`commit()`/`rollback()` stay for the flows `transactional()` cannot express (`SessionAuthenticator::login()`).
- Errors throw `DatabaseException` (SQL with placeholders, engine code and message, never the bound values).
- Values come back as the engine gives them: native int/float with mysqli, strings with pgsql and the legacy driver. Cast at the boundary (`(int) $row['nb']`).
- `DatabaseClock::now()` is the database "now" (`$db->idate(dol_now())`) as a `DateTimeImmutable` in UTC.

Hand-written SQL must run on MySQL/MariaDB and PostgreSQL as is (native drivers do not convert it): no `IFNULL` (use `COALESCE`), no backquotes, no `LIMIT a, b` (`LIMIT :limit OFFSET :offset`); `LIKE` is case sensitive on PostgreSQL, so use `ILIKE` there when the legacy SQL relied on `DoliDBPgsql` turning `LIKE` into `ILIKE` (`$connection->dialect() === 'pgsql'`). PostgreSQL types the parameters: ints are sent as `$1::integer` (`::bigint` beyond int4), floats as `::numeric`; a string or null in a position without context (`:x IS NULL`, `COALESCE(:x, :y)`) needs an explicit `CAST(:x AS ...)`. String literals written in the SQL follow `SqlLiterals` (on PostgreSQL no backslash escape, no `E'...'` nor `$$...$$`). SQL comments (`--`, `/* */`, `#`) are not tokenised: MVC SQL must not contain comments. A quote inside one opens a literal up to the next quote of the statement (the placeholders in between are not bound: the binding throws on the unused parameter, or the engine refuses the statement); with no later quote the statement runs unchanged, comment included. A `:name` inside one is read as a parameter: the native drivers never splice a value into the SQL (the engine refuses the statement), but `LegacyDriver` interpolates its escaped value inside the comment. Table or column names are never bound: one that does not come from the code must be checked against a whitelist (`NotesRepository` checks the table element, `Query` the mapping).

ORM (`Dolibarr\Core\Orm`): entities are final readonly classes mapped by attributes (`#[Table('user', element: 'user')]`, `#[Id]`, `#[Column('statut')]`, `#[Column(cast: 'date')]`, `#[BelongsTo]`, `#[HasMany]`, `#[BelongsToMany]`, `#[ExtraFields]`, `#[Table('mvc_orm_tag', entityScoped: false)]` for a table without multicompany scope); one `Orm` per request (container service), the mapping cached in `DOL_DATA_ROOT/cache/orm_mappings.php`. A repository names its entity and adds its scopes as methods returning a `Query`:

```php
/** @extends Repository<Article> */
final class ArticleRepository extends Repository
{
	protected string $entityClass = Article::class;

	/** @return Query<Article> */
	public function published(): Query
	{
		return $this->query('a')->where('status', ArticleStatus::Published);
	}
}

$query = $articles->published()->where('title', 'like', 'Dup%')->whereIn('fk_author', [3, 7])->with('author', 'tags');
$query->orderBy($query->isSortable($sortField) ? $sortField : 'title'); // $sortField from the URL
$page = $query->paginate(page: 2, perPage: 25);
$article = $articles->findOrFail(12);
$articles->save($articles->modify($article, ['title' => 'New title'])); // UPDATE of the changed columns only
```

Rules (spec §5.4):

- `#[ExtraFields]`: the extra field names come from Dolibarr's `llx_extrafields` (`ExtraFieldCatalog`, implemented by `LegacyExtraFieldCatalog`: `elementtype` = the mapping's table, entity 0 or the current entity, by `pos`, separators excluded, one statement per element type and request), the filter of `ExtraFields::fetch_name_optionals_label()`. A name with a character outside `[A-Za-z0-9_]` (the legacy accepts `-`), starting with a digit, longer than 59 characters (its alias `ef__<name>` must fit the 63 bytes of a PostgreSQL identifier), or that is a technical (`rowid`, `fk_object`, `tms`, `import_key`) or secret column, is skipped with a warning in the log: the property has no such key and `save()` refuses it. With `MAIN_EXTRAFIELDS_DISABLED` the catalog is empty (no join) and `delete()` leaves the `<table>_extrafields` row, like the legacy `deleteExtraFields()`. Names are kept exactly as stored (as the legacy creates the column; PostgreSQL folds it to lower case, mapped back to the stored name). With at least one name, every query gets `LEFT JOIN <table>_extrafields AS ef` and selects `ef.<name>`; with none, no join and the property is `[]`. Columns of the table the catalog does not name (another entity's attributes, orphan columns) are never read, and `save()` refuses a key outside the catalog (`InvalidArgumentException`, before any statement).

- `where`/`orderBy`/`select` accept the mapped properties or columns, and the columns of a join declared with an entity class or a column list: an unknown column throws, so a sort from the URL goes through `isSortable()` first (else the default sort). The secret columns (`pass`, `pass_crypted`, `pass_temp`, `api_key`, `openid`, `ldap_sid`) are refused on any alias.
- `whereRaw()`/`orWhereRaw()`/`selectRaw()` take code-authored SQL with bound parameters, never request input.
- `legacyFragment()` is the only place for unbound SQL: the fragments of the legacy hooks (`printFieldListSelect/From/Where`) and of `extrafields_list_search_sql.tpl.php`, converted by `DoliDB::convertSQLFromMysql()` on PostgreSQL. A WHERE fragment that starts with `OR` is appended as is, so it escapes the entity scope and the other conditions (`entity IN (...) AND ... OR x`), exactly like the legacy `$sql .= $hookmanager->resPrint`; a fragment without a leading `AND`/`OR` is wrapped in `AND (...)`. One documented exception selects a secret: `UserRepository::selectApiKey()` adds `u.api_key` through `legacyFragment()` for the admin-only ApiKey list column (legacy parity, decrypted by `UserListChrome`), only when that column is shown.
- `toLegacySql($db)` gives an interpolated MySQL copy of the query for the legacy consumers of the list SQL (`list_print_total.tpl.php`, `printFieldListFooter` hooks); the MVC core never runs it.
- `NaturalSearch::apply()`/`applyPhone()` are the bound equivalents of `natural_search()` and `dol_natural_search_phone()` (same rows, modes 0 to 3); an input with nothing searchable (`|`, `""`) leaves the query unfiltered instead of the legacy SQL error.
- Multicompany: every mapping is entity scoped unless its `#[Table]` declares `entityScoped: false`; a scoped mapping must map the `entity` column and an unscoped one must not (`MappingReader` throws `LogicException` naming the class otherwise), so a forgotten `entity` column never drops the filter silently. Every query, UPDATE and DELETE of a scoped entity is limited to `entity IN (getEntity(element))`; a write outside the scope, or on a missing row, throws `EntityNotFoundException`, and `entity` cannot be moved out of the scope. `withoutEntityScope()` (on a query or a repository) is the explicit opt-out (login, multicompany administration); relations then load unscoped too.

The PostgreSQL suite (see the commands at the top) runs the drivers, the ORM, `UserRepository`, the rights tab and the notes on a second, test-only PostgreSQL install; the website stays on MariaDB.

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
