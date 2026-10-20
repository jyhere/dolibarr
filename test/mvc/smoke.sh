#!/usr/bin/env bash
# HTTP smoke test of the MVC core coexisting with legacy pages.
# Usage: smoke.sh <base_url> [login] [password]
set -euo pipefail

BASE="${1:?usage: smoke.sh <base_url> [login] [password]}"
BASE="${BASE%/}"
LOGIN="${2:-admin}"
PASS_="${3:-admin}"
# SQL client used to remove the users created by the smoke test. SMOKE_MYSQL (a full client command) overrides;
# otherwise the host mysql client on the dev docker port, then `docker compose exec mysql` from the compose dir.
SMOKE_MYSQL="${SMOKE_MYSQL:-}"
COMPOSE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
# env_value <key> <default>: a value of the compose .env file
env_value() {
	local v
	v="$(grep -E "^$1=" "$COMPOSE_DIR/.env" 2>/dev/null | tail -1 | cut -d= -f2- || true)"
	printf '%s' "${v:-$2}"
}
DB_USER="$(env_value MYSQL_USER dolibarr_user)"
DB_PASS="$(env_value MYSQL_PASSWORD dolibarr_password)"
DB_NAME="$(env_value MYSQL_DATABASE dolibarr)"
# A password the default policy accepts (12 characters at least)
SMOKE_PASSWORD="${SMOKE_PASSWORD:-Smoke1234!Smoke1234!}"

JARS=()
trap 'rm -f ${JARS[@]+"${JARS[@]}"}' EXIT
# newjar <varname>: creates a jar in the main shell (no subshell) and registers it for cleanup
newjar() { local j; j="$(mktemp)"; JARS+=("$j"); printf -v "$1" '%s' "$j"; }

fail() { echo "FAIL $1 (got $2)"; exit 1; }
ok() { echo "PASS $1"; }

# expect_code <label> <expected_code> <curl args...>
expect_code() {
	local label="$1" want="$2"; shift 2
	local got
	got="$(curl -s -o /dev/null -w '%{http_code}' "$@")" || got="curl error"
	[ "$got" = "$want" ] && ok "$label" || fail "$label" "$got, expected $want"
}

# expect_grep <label> <pattern> <expected_count> <curl args...> (counts matching lines; GREP_OPTS=-i for case-insensitive)
expect_grep() {
	local label="$1" pattern="$2" want="$3"; shift 3
	local got
	got="$(curl -s "$@" | grep -c ${GREP_OPTS:-} -- "$pattern" || true)"
	[ "$got" = "$want" ] && ok "$label" || fail "$label" "$got matches, expected $want"
}

# expect_location_suffix <label> <suffix> <curl args...> : 3xx whose Location ends with suffix
expect_location_suffix() {
	local label="$1" suffix="$2"; shift 2
	local out code loc
	out="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$@")" || out="curl error"
	code="${out%% *}"; loc="${out#* }"
	case "$code" in 30?) ;; *) fail "$label" "$out";; esac
	case "$loc" in *"$suffix") ok "$label";; *) fail "$label" "location $loc, expected suffix $suffix";; esac
}

# smoke_sql <sql>: runs a statement, prints the result without header; on failure prints the client errors
smoke_sql() {
	local cmd err out
	err="$(mktemp)"; JARS+=("$err")
	if [ -n "$SMOKE_MYSQL" ]; then
		read -r -a cmd <<< "$SMOKE_MYSQL"
		"${cmd[@]}" -N -B -e "$1" 2>"$err" && return 0
		echo "SQL error (SMOKE_MYSQL): $(cat "$err")" >&2
		return 1
	fi
	if command -v mysql > /dev/null 2>&1; then
		if out="$(MYSQL_PWD="$DB_PASS" mysql -h127.0.0.1 -P33306 -u"$DB_USER" "$DB_NAME" -N -B -e "$1" 2>"$err")"; then
			printf '%s\n' "$out"
			return 0
		fi
		echo "host mysql client failed, trying docker compose: $(cat "$err")" >&2
	fi
	(cd "$COMPOSE_DIR" && docker compose exec -T -e MYSQL_PWD="$DB_PASS" mysql mysql -u"$DB_USER" "$DB_NAME" -N -B -e "$1") 2>"$err" && return 0
	echo "SQL error (docker compose): $(cat "$err")" >&2
	return 1
}

# Removes the users created by the smoke test (login smoke_*): through POST /user/{id}/delete while the jar
# $CLEANUP_JAR is logged in, then in SQL for the leftovers and what their creation wrote, and the smoke groups.
# SMOKE_IDS: ids of the smoke users, whose agenda events (written by the legacy triggers) outlive a deletion.
CLEANUP_JAR=""
SMOKE_IDS=()
cleanup_smoke_users() {
	local ids="SELECT rowid FROM llx_user WHERE login LIKE 'smoke\\_%'" id tok events
	for id in $(smoke_sql "$ids" || true); do
		SMOKE_IDS+=("$id")
		[ -n "$CLEANUP_JAR" ] || continue
		tok="$(token_from "$CLEANUP_JAR" "/user/$id")"
		[ -n "$tok" ] && curl -s -o /dev/null -b "$CLEANUP_JAR" -c "$CLEANUP_JAR" -X POST --data-urlencode "token=$tok" \
			-d "action=confirm_delete&confirm=yes" "$BASE/user/$id/delete" || true
	done
	events="a.fk_element IN ($ids)"
	[ ${#SMOKE_IDS[@]} -gt 0 ] && events="($events OR a.fk_element IN ($(IFS=,; printf '%s' "${SMOKE_IDS[*]}")))"
	smoke_sql "DELETE FROM llx_usergroup_user WHERE fk_usergroup IN (SELECT rowid FROM llx_usergroup WHERE nom LIKE 'smoke\\_%');
		DELETE FROM llx_usergroup WHERE nom LIKE 'smoke\\_%';
		DELETE r FROM llx_actioncomm_resources r JOIN llx_actioncomm a ON a.id = r.fk_actioncomm WHERE a.elementtype = 'user' AND $events;
		DELETE a FROM llx_actioncomm a WHERE a.elementtype = 'user' AND $events;
		DELETE FROM llx_user_rights WHERE fk_user IN ($ids);
		DELETE FROM llx_usergroup_user WHERE fk_user IN ($ids);
		DELETE FROM llx_user_param WHERE fk_user IN ($ids);
		DELETE FROM llx_user_extrafields WHERE fk_object IN ($ids);
		DELETE FROM llx_categorie_user WHERE fk_user IN ($ids);
		DELETE FROM llx_user WHERE login LIKE 'smoke\\_%';"
}

token_from() { # <jar> <path>
	curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//' || true
}

JSON=(-H 'Accept: application/json')

echo "== Anonymous"
expect_code "404 unknown route" 404 "$BASE/nexistepas"
expect_code "404 unknown route json" 404 "${JSON[@]}" "$BASE/nexistepas"
expect_grep "404 json body" '{"error":"not_found"}' 1 "${JSON[@]}" "$BASE/nexistepas"
expect_code "src not served" 403 "$BASE/src/Core/Kernel.php"
expect_code "legacy card without session" 200 "$BASE/user/card.php?id=1"
expect_code "root / 200" 200 "$BASE/"
GREP_OPTS=-i expect_grep "root / is text/html (not a download)" '^content-type: text/html' 1 -D - -o /dev/null "$BASE/"
expect_grep "root / shows login form" 'name="username"' 1 "$BASE/"
expect_grep "admin/ index.php is executed, not served raw" '<?php' 0 "$BASE/admin/"
expect_grep "install/ index.php is executed, not served raw" '<?php' 0 "$BASE/install/"
expect_grep "legacy card shows login form" 'name="username"' 1 "$BASE/user/card.php?id=1"
expect_code "user/1 anonymous 302" 302 "$BASE/user/1"
LOC="$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/user/1")"
case "$LOC" in "$BASE/login?backtopage="*) ok "user/1 redirects to login?backtopage";; *) fail "user/1 redirect target" "$LOC";; esac
expect_code "user/1 anonymous json 401" 401 "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 anonymous json body" '{"error":"not_logged"}' 1 "${JSON[@]}" "$BASE/user/1"
LOC="$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/logout?urlfrom=//evil.example/x")"
case "$LOC" in "$BASE/login?"*) ok "logout open redirect anonymous goes to login";; *) fail "logout open redirect anonymous" "$LOC";; esac
# A missing static file: 404 from nginx (or app.php before main.inc.php), never a session
expect_code "missing asset 404" 404 "$BASE/theme/eldy/img/nothere.png"
GREP_OPTS=-i expect_grep "missing asset sets no cookie" '^set-cookie' 0 -D - -o /dev/null "$BASE/theme/eldy/img/nothere.png"
expect_code "unmatched .json is a 404" 404 "$BASE/nexistepas.json"
expect_grep "unmatched .json body" '{"error":"not_found"}' 1 "$BASE/nexistepas.json"
expect_code "login page 200" 200 "$BASE/login"
LOGINACTION="$(curl -s "$BASE/login" | grep -o 'action="[^"]*"' | head -1)"
case "$LOGINACTION" in 'action="/login'*) ok "login form action";; *) fail "login form action" "$LOGINACTION";; esac

echo "== New login"
newjar J1
T1="$(token_from "$J1" /login)"
[ -n "$T1" ] || fail "login token present" "none"
ok "login token present"
expect_code "POST /login without token 403" 403 -X POST -d "username=$LOGIN&password=$PASS_" "$BASE/login"
expect_location_suffix "POST /login bad password" "/login" -b "$J1" -c "$J1" -X POST \
	--data-urlencode "token=$T1" -d "actionlogin=login&loginfunction=loginfunction" \
	--data-urlencode "username=$LOGIN" --data-urlencode "password=wrong-$PASS_-x" "$BASE/login"
T1="$(token_from "$J1" /login)"
expect_location_suffix "POST /login open redirect ignored" "/index.php" -b "$J1" -c "$J1" -X POST \
	--data-urlencode "token=$T1" -d "actionlogin=login&loginfunction=loginfunction" \
	--data-urlencode "username=$LOGIN" --data-urlencode "password=$PASS_" \
	--data-urlencode "backtopage=https://evil.example" "$BASE/login"

echo "== Logged in (new world)"
expect_grep "legacy card has no login form" 'name="username"' 0 -b "$J1" "$BASE/user/card.php?id=1"
expect_code "legacy card 200" 200 -b "$J1" "$BASE/user/card.php?id=1"
expect_code "user/1 200" 200 -b "$J1" "$BASE/user/1"
expect_grep "user/1 mainbody" 'id="mainbody"' 1 -b "$J1" "$BASE/user/1"
# The card and the documents/events blocks below it (card.php l.1755 and l.3316)
expect_grep "user/1 fichecenter" 'fichecenter' 2 -b "$J1" "$BASE/user/1"
expect_code "user/1/ trailing slash" 200 -b "$J1" "$BASE/user/1/"
expect_grep "user/1 Accept */* is HTML" '<html' 1 -H 'Accept: */*' -b "$J1" "$BASE/user/1"
expect_code "user/1 json 200" 200 -b "$J1" "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 json login" '"login":"admin"' 1 -b "$J1" "${JSON[@]}" "$BASE/user/1"
# No key starting with "pass" (pass, pass_crypted, pass_temp, password...); forcePasswordChange is a flag, not a secret
GREP_OPTS=-i expect_grep "user/1 json no pass" '"pass' 0 -b "$J1" "${JSON[@]}" "$BASE/user/1"
# Only the exposed data: {"user":{...}} with the flat UserView::toArray() keys, no presentation data
expect_grep "user/1 json is only the exposed user" '^{"user":{[^{}]*}}$' 1 -b "$J1" "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 json no title" '"title"' 0 -b "$J1" "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 json no hook html" 'hookFormObjectOptions' 0 -b "$J1" "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 json no presentation flags" '"showActions"\|"bodyCss"\|"canSeeSalary"' 0 -b "$J1" "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1.json login" '"login":"admin"' 1 -b "$J1" "$BASE/user/1.json"
expect_grep "user/1?format=json login" '"login":"admin"' 1 -b "$J1" "$BASE/user/1?format=json"
expect_code "user/abc 404" 404 -b "$J1" "$BASE/user/abc"
expect_code "user 404 (list in plan 2)" 404 -b "$J1" "$BASE/user"
expect_code "user/999999 404" 404 -b "$J1" "$BASE/user/999999"
expect_code "login while logged in 302" 302 -b "$J1" "$BASE/login"

echo "== User creation (/user/new)"
smoke_sql "SELECT 1" > /dev/null || fail "SQL client for the smoke users cleanup" "no working client (set SMOKE_MYSQL)"
trap 'cleanup_smoke_users || true; rm -f ${JARS[@]+"${JARS[@]}"}' EXIT
expect_code "user/new 200" 200 -b "$J1" "$BASE/user/new"
expect_grep "user/new create form" 'name="createuser"' 1 -b "$J1" "$BASE/user/new"
expect_grep "user/new posts to itself" '<form action="[^"]*/user/new" method="POST" name="createuser">' 1 -b "$J1" "$BASE/user/new"
SMOKE_LOGIN="smoke_$(date +%s)$$"
TN="$(token_from "$J1" /user/new)"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" \
	--data-urlencode "lastname=Smoke" --data-urlencode "login=$SMOKE_LOGIN" --data-urlencode "password=$SMOKE_PASSWORD" \
	-d "employee=1&entity=1" "$BASE/user/new")"
case "$OUT" in "302 $BASE/user/"[0-9]*) ok "POST /user/new 302 to /user/<id>";; *) fail "POST /user/new" "$OUT";; esac
NEWID="${OUT##*/}"
GOT="$(curl -s -w '\n%{http_code}' -b "$J1" "$BASE/user/$NEWID")"
[ "${GOT##*$'\n'}" = "200" ] && ok "user/<id> of the new user 200" || fail "user/<id> of the new user" "${GOT##*$'\n'}"
case "$GOT" in *"$SMOKE_LOGIN"*) ok "user/<id> shows the new login";; *) fail "user/<id> shows the new login" "not found";; esac
TN="$(token_from "$J1" /user/new)"
expect_grep "duplicate login redisplays the form" 'name="createuser"' 1 -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" \
	--data-urlencode "lastname=Smoke" --data-urlencode "login=$SMOKE_LOGIN" --data-urlencode "password=$SMOKE_PASSWORD" "$BASE/user/new"
expect_grep "invalid token redisplays the form" 'name="createuser"' 1 -b "$J1" -c "$J1" -X POST -d "token=bogus" \
	--data-urlencode "lastname=Smoke" --data-urlencode "login=${SMOKE_LOGIN}b" --data-urlencode "password=$SMOKE_PASSWORD" "$BASE/user/new"
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_user WHERE login = '${SMOKE_LOGIN}b'")" = "0" ] && ok "invalid token creates no user" || fail "invalid token creates no user" "a user"
cleanup_smoke_users
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_user WHERE login LIKE 'smoke\\_%'")" = "0" ] && ok "smoke users removed" || fail "smoke users removed" "some left"

echo "== User edit (/user/{id}/edit)"
expect_code "user/1/edit 200" 200 -b "$J1" "$BASE/user/1/edit"
expect_grep "user/1/edit edit form" 'name="updateuser"' 1 -b "$J1" "$BASE/user/1/edit"
expect_grep "user/1/edit posts to itself" '<form action="[^"]*/user/1/edit" method="POST" name="updateuser"' 1 -b "$J1" "$BASE/user/1/edit"
expect_grep "user/1 Modify button points to the edit form" 'href="[^"]*/user/1/edit"' 1 -b "$J1" "$BASE/user/1"
# The writes run on a smoke user: like card.php, the update clears the fields the POST does not carry,
# and a hand-made POST on user 1 would wipe what the form shows.
SMOKE_LOGIN="smoke_$(date +%s)$$e"
TN="$(token_from "$J1" /user/new)"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" \
	--data-urlencode "lastname=Edited" --data-urlencode "login=$SMOKE_LOGIN" --data-urlencode "password=$SMOKE_PASSWORD" \
	-d "employee=1&entity=1" "$BASE/user/new")"
case "$OUT" in "302 $BASE/user/"[0-9]*) ok "smoke user for the edit created";; *) fail "smoke user for the edit" "$OUT";; esac
EDITID="${OUT##*/}"
TE="$(token_from "$J1" "/user/$EDITID/edit")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TE" \
	--data-urlencode "lastname=Edited" --data-urlencode "login=$SMOKE_LOGIN" -d "action=update&admin=0&employee=1&entity=1&job=Smoke" "$BASE/user/$EDITID/edit")"
[ "$OUT" = "302 $BASE/user/$EDITID" ] && ok "POST /user/<id>/edit job=Smoke 302 to /user/<id>" || fail "POST /user/<id>/edit job=Smoke" "$OUT"
expect_grep "user/<id> shows the new job" '>Smoke<' 1 -b "$J1" "$BASE/user/$EDITID"
TE="$(token_from "$J1" "/user/$EDITID/edit")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TE" \
	--data-urlencode "lastname=Edited" --data-urlencode "login=$SMOKE_LOGIN" -d "action=update&admin=0&employee=1&entity=1&job=" "$BASE/user/$EDITID/edit")"
[ "$OUT" = "302 $BASE/user/$EDITID" ] && ok "POST /user/<id>/edit job= (restore) 302" || fail "POST /user/<id>/edit job= (restore)" "$OUT"
[ "$(smoke_sql "SELECT COALESCE(job, '') FROM llx_user WHERE rowid = $EDITID")" = "" ] && ok "job restored" || fail "job restored" "not empty"
expect_grep "invalid token redisplays the edit form" 'name="updateuser"' 1 -b "$J1" -c "$J1" -X POST -d "token=bogus" \
	--data-urlencode "lastname=Bogus" --data-urlencode "login=$SMOKE_LOGIN" "$BASE/user/$EDITID/edit"
[ "$(smoke_sql "SELECT lastname FROM llx_user WHERE rowid = $EDITID")" = "Edited" ] && ok "invalid token writes nothing" || fail "invalid token writes nothing" "lastname changed"
cleanup_smoke_users
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_user WHERE login LIKE 'smoke\\_%'")" = "0" ] && ok "smoke users removed" || fail "smoke users removed" "some left"

echo "== User actions (status, password, groups, delete)"
CLEANUP_JAR="$J1"
expect_grep "user/1 groups block" '<select[^>]*name="group"' 1 -b "$J1" "$BASE/user/1"
expect_grep "user/1 groups form posts to /user/1/groups" '<form action="[^"]*/user/1/groups" method="POST">' 1 -b "$J1" "$BASE/user/1"
expect_grep "user/1 documents block" '<!-- Start show_document -->' 1 -b "$J1" "$BASE/user/1"
expect_grep "user/1 latest events block" 'formactions->showactions' 1 -b "$J1" "$BASE/user/1"
SMOKE_LOGIN="smoke_$(date +%s)$$a"
TN="$(token_from "$J1" /user/new)"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" \
	--data-urlencode "lastname=Actions" --data-urlencode "login=$SMOKE_LOGIN" --data-urlencode "password=$SMOKE_PASSWORD" \
	-d "employee=1&entity=1" "$BASE/user/new")"
case "$OUT" in "302 $BASE/user/"[0-9]*) ok "smoke user for the actions created";; *) fail "smoke user for the actions" "$OUT";; esac
AID="${OUT##*/}"
SMOKE_IDS+=("$AID")
expect_grep "user/<id> Disable button opens the dialog" "href=\"[^\"]*/user/$AID?confirm=disable\"" 1 -b "$J1" "$BASE/user/$AID"
expect_grep "user/<id>?confirm=disable dialog posts to the status route" "<form method=\"POST\" action=\"[^\"]*/user/$AID/status\"" 1 -b "$J1" "$BASE/user/$AID?confirm=disable"
TA="$(token_from "$J1" "/user/$AID")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TA" -d "action=confirm_disable&confirm=yes" "$BASE/user/$AID/status")"
[ "$OUT" = "302 $BASE/user/$AID" ] && ok "POST /user/<id>/status disable 302 to the card" || fail "POST /user/<id>/status disable" "$OUT"
[ "$(smoke_sql "SELECT statut FROM llx_user WHERE rowid = $AID")" = "0" ] && ok "user disabled" || fail "user disabled" "still enabled"
expect_grep "card shows the disabled user (Reactivate button)" "href=\"[^\"]*/user/$AID?confirm=enable\"" 1 -b "$J1" "$BASE/user/$AID"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST -d "token=bogus&action=confirm_enable&confirm=yes" "$BASE/user/$AID/status")"
[ "$OUT" = "302 $BASE/user/$AID" ] && ok "POST /user/<id>/status with an invalid token 302" || fail "POST /user/<id>/status invalid token" "$OUT"
[ "$(smoke_sql "SELECT statut FROM llx_user WHERE rowid = $AID")" = "0" ] && ok "invalid token enables nothing" || fail "invalid token enables nothing" "enabled"
TA="$(token_from "$J1" "/user/$AID")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TA" -d "action=confirm_enable&confirm=yes" "$BASE/user/$AID/status")"
[ "$OUT" = "302 $BASE/user/$AID" ] && ok "POST /user/<id>/status enable 302 to the card" || fail "POST /user/<id>/status enable" "$OUT"
[ "$(smoke_sql "SELECT statut FROM llx_user WHERE rowid = $AID")" = "1" ] && ok "user enabled" || fail "user enabled" "still disabled"
expect_code "user/<id>/password 200" 200 -b "$J1" "$BASE/user/$AID/password"
expect_grep "user/<id>/password shows the dialog" 'name="action" value="confirm_password"' 1 -b "$J1" "$BASE/user/$AID/password"
expect_code "GET /user/<id>/status is not allowed" 405 -b "$J1" "$BASE/user/$AID/status"
PASS_BEFORE="$(smoke_sql "SELECT pass_crypted FROM llx_user WHERE rowid = $AID")"
TA="$(token_from "$J1" "/user/$AID")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TA" -d "action=confirm_password&confirm=yes" "$BASE/user/$AID/password")"
[ "$OUT" = "302 $BASE/user/$AID" ] && ok "POST /user/<id>/password 302 to the card" || fail "POST /user/<id>/password" "$OUT"
[ "$(smoke_sql "SELECT pass_crypted FROM llx_user WHERE rowid = $AID")" != "$PASS_BEFORE" ] && ok "password changed" || fail "password changed" "same hash"
GOT="$(curl -s -b "$J1" "$BASE/user/$AID")"
# PasswordChangedTo in en_US or fr_FR, the languages of the dev instances
printf '%s' "$GOT" | grep -qE "Password changed to: |Mot de passe modifi(é|&eacute;) en: " && ok "card shows the PasswordChangedTo warning" || fail "card shows the PasswordChangedTo warning" "no message"
smoke_sql "INSERT INTO llx_usergroup (nom, entity, datec) VALUES ('smoke_group_$$', 1, NOW())" > /dev/null
GID="$(smoke_sql "SELECT rowid FROM llx_usergroup WHERE nom = 'smoke_group_$$'")"
TA="$(token_from "$J1" "/user/$AID")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TA" -d "action=addgroup&group=$GID" "$BASE/user/$AID/groups")"
[ "$OUT" = "302 $BASE/user/$AID" ] && ok "POST /user/<id>/groups addgroup 302" || fail "POST /user/<id>/groups addgroup" "$OUT"
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_usergroup_user WHERE fk_user = $AID AND fk_usergroup = $GID")" = "1" ] && ok "user added to the group" || fail "user added to the group" "no row"
expect_grep "card has a POST removal form for the group" "name=\"group\" value=\"$GID\"" 1 -b "$J1" "$BASE/user/$AID"
TA="$(token_from "$J1" "/user/$AID")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TA" -d "action=removegroup&group=$GID" "$BASE/user/$AID/groups")"
[ "$OUT" = "302 $BASE/user/$AID" ] && ok "POST /user/<id>/groups removegroup 302" || fail "POST /user/<id>/groups removegroup" "$OUT"
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_usergroup_user WHERE fk_user = $AID AND fk_usergroup = $GID")" = "0" ] && ok "user removed from the group" || fail "user removed from the group" "row left"
expect_grep "user/<id>?confirm=delete dialog posts to the delete route" "<form method=\"POST\" action=\"[^\"]*/user/$AID/delete\"" 1 -b "$J1" "$BASE/user/$AID?confirm=delete"
TA="$(token_from "$J1" "/user/$AID")"
expect_location_suffix "POST /user/<id>/delete 302 to the list" "/user/list.php?restore_lastsearch_values=1" -b "$J1" -c "$J1" -X POST \
	--data-urlencode "token=$TA" -d "action=confirm_delete&confirm=yes" "$BASE/user/$AID/delete"
expect_code "user/<id> deleted 404" 404 -b "$J1" "$BASE/user/$AID"
cleanup_smoke_users
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_user WHERE login LIKE 'smoke\\_%'")" = "0" ] && ok "smoke users removed" || fail "smoke users removed" "some left"
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_usergroup WHERE nom LIKE 'smoke\\_%'")" = "0" ] && ok "smoke groups removed" || fail "smoke groups removed" "some left"
CLEANUP_JAR=""

echo "== Notes component (/{entity}/{id}/note)"
# The note of user 1 and its modifier are restored at the end, and the agenda events USER_MODIFY written meanwhile removed
NOTE_ORIG="$(smoke_sql "SELECT IFNULL(note_public, '<NULL>'), IFNULL(fk_user_modif, '<NULL>') FROM llx_user WHERE rowid = 1")"
NOTE_PUB_ORIG="$(printf '%s' "$NOTE_ORIG" | cut -f1)"
NOTE_MODIF_ORIG="$(printf '%s' "$NOTE_ORIG" | cut -f2)"
MAX_AC="$(smoke_sql "SELECT IFNULL(MAX(id), 0) FROM llx_actioncomm")"
sql_or_null() { [ "$1" = "<NULL>" ] && printf 'NULL' || printf "'%s'" "$(printf '%s' "$1" | sed "s/'/''/g")"; }
restore_note() {
	smoke_sql "UPDATE llx_user SET note_public = $(sql_or_null "$NOTE_PUB_ORIG"), fk_user_modif = $(sql_or_null "$NOTE_MODIF_ORIG") WHERE rowid = 1;
		DELETE r FROM llx_actioncomm_resources r JOIN llx_actioncomm a ON a.id = r.fk_actioncomm WHERE a.id > $MAX_AC AND a.elementtype = 'user' AND a.fk_element = 1;
		DELETE FROM llx_actioncomm WHERE id > $MAX_AC AND elementtype = 'user' AND fk_element = 1;" > /dev/null
}
# A failing check exits: the note is restored on the way out
trap 'restore_note; rm -f ${JARS[@]+"${JARS[@]}"}' EXIT
expect_code "GET /user/1/note 200" 200 -b "$J1" "$BASE/user/1/note"
expect_grep "notes page has the NotePublic label" 'Note (publique)\|Public note' 1 -b "$J1" "$BASE/user/1/note"
expect_grep "notes page has the legacy notes template structure" 'BEGIN PHP TEMPLATE NOTES' 1 -b "$J1" "$BASE/user/1/note"
expect_grep "note tab of the card points to the route" 'id="note" class="tab inline-block valignmiddle" href="/user/1/note"' 1 -b "$J1" "$BASE/user/1"
expect_code "GET /nothing/1/note 404" 404 -b "$J1" "$BASE/nothing/1/note"
EDIT="$(curl -s -b "$J1" -c "$J1" "$BASE/user/1/note" | grep -o 'href="[^"]*/user/1/note?action=editnote_public[^"]*"' | head -1 | sed 's/^href="//;s/"$//;s/&amp;/\&/g')"
[ -n "$EDIT" ] && ok "notes page has the edit link of the public note" || fail "edit link of the public note" "none"
expect_grep "edit mode shows the textarea posting to the route" '<form method="post" action="/user/1/note">' 1 -b "$J1" "$BASE$EDIT"
TN="$(token_from "$J1" /user/1/note)"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" -d "action=setnote_public" --data-urlencode "note_public=<b>smoke</b>" "$BASE/user/1/note")"
[ "$OUT" = "302 $BASE/user/1/note" ] || fail "POST /user/1/note setnote_public" "$OUT"
ok "POST /user/1/note setnote_public 302"
# editfieldval() (textarea type) prints the note as HTML, as user/note.php does
expect_grep "the note is shown as HTML" '^<b>smoke</b>$' 1 -b "$J1" "$BASE/user/1/note"
expect_grep "legacy note.php shows the same note" '^<b>smoke</b>$' 1 -b "$J1" "$BASE/user/note.php?id=1"
expect_grep "json exposes the public note" '"notePublic":"<b>smoke</b>"' 1 "${JSON[@]}" -b "$J1" "$BASE/user/1/note"
OUT="$(curl -s -o /dev/null -w '%{http_code}' -b "$J1" -c "$J1" -X POST -d "token=bogus&action=setnote_public&note_public=bogus" "$BASE/user/1/note")"
[ "$OUT" = "302" ] && [ "$(smoke_sql "SELECT note_public FROM llx_user WHERE rowid = 1")" = "<b>smoke</b>" ] && ok "invalid token writes no note" || fail "invalid token writes no note" "$OUT"
TN="$(token_from "$J1" /user/1/note)"
OUT="$(curl -s -o /dev/null -w '%{http_code}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" -d "action=setnote_public&note_public=" "$BASE/user/1/note")"
[ "$OUT" = "302" ] && [ "$(smoke_sql "SELECT IFNULL(note_public, '<NULL>') FROM llx_user WHERE rowid = 1")" = "<NULL>" ] && ok "empty note_public clears the note" || fail "empty note_public clears the note" "$OUT"
restore_note
trap 'rm -f ${JARS[@]+"${JARS[@]}"}' EXIT
[ "$(smoke_sql "SELECT IFNULL(note_public, '<NULL>'), IFNULL(fk_user_modif, '<NULL>') FROM llx_user WHERE rowid = 1")" = "$NOTE_ORIG" ] && ok "note of user 1 restored" || fail "note of user 1 restored" "differs"

echo "== Rights tab (/user/{id}/rights)"
CLEANUP_JAR="$J1"
trap 'cleanup_smoke_users || true; rm -f ${JARS[@]+"${JARS[@]}"}' EXIT
expect_code "GET /user/1/rights 200" 200 -b "$J1" "$BASE/user/1/rights"
GOT="$(curl -s -b "$J1" "$BASE/user/1/rights")"
# img_picto('switch_on') prints the font awesome toggle-on
case "$GOT" in *"fa-toggle-on"*) ok "rights page has switch_on pictos";; *) fail "rights page has switch_on pictos" "none";; esac
case "$GOT" in *'name="forbreakperms_'*) ok "rights page has the module break lines";; *) fail "rights page has the module break lines" "none";; esac
case "$GOT" in *'DOLUSER_PERMS_HIDE_GRP'*) ok "rights page has the collapse script";; *) fail "rights page has the collapse script" "none";; esac
case "$GOT" in *'href="/user/perms.php?id=1&action='*) fail "rights page has no GET toggle" "a link to perms.php";; *) ok "rights page has no GET toggle";; esac
expect_grep "rights tab of the card points to the route" 'id="rights" class="tab inline-block valignmiddle" href="/user/1/rights"' 1 -b "$J1" "$BASE/user/1"
expect_grep "rights json is a non empty list" '"rights":\[{"module":"' 1 "${JSON[@]}" -b "$J1" "$BASE/user/1/rights"
SMOKE_LOGIN="smoke_$(date +%s)$$r"
TN="$(token_from "$J1" /user/new)"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TN" \
	--data-urlencode "lastname=Rights" --data-urlencode "login=$SMOKE_LOGIN" --data-urlencode "password=$SMOKE_PASSWORD" \
	-d "employee=1&entity=1" "$BASE/user/new")"
case "$OUT" in "302 $BASE/user/"[0-9]*) ok "smoke user for the rights created";; *) fail "smoke user for the rights" "$OUT";; esac
RID="${OUT##*/}"
SMOKE_IDS+=("$RID")
USER_RIGHTS="SELECT COUNT(*) FROM llx_user_rights ur JOIN llx_rights_def r ON r.id = ur.fk_id AND r.entity = ur.entity WHERE r.module = 'user' AND ur.fk_user = $RID"
READ_ID="$(smoke_sql "SELECT id FROM llx_rights_def WHERE module = 'user' AND perms = 'user' AND subperms = 'lire' AND entity = 1")"
TR="$(token_from "$J1" "/user/$RID/rights")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TR" -d "action=addrights&confirm=yes&module=user&updatedmodulename=user" "$BASE/user/$RID/rights")"
[ "$OUT" = "302 $BASE/user/$RID/rights?updatedmodulename=user" ] && ok "POST /user/<id>/rights addrights module=user 302" || fail "POST /user/<id>/rights addrights" "$OUT"
[ "$(smoke_sql "$USER_RIGHTS")" -gt 0 ] && ok "rights of the user module granted" || fail "rights of the user module granted" "none"
expect_grep "the page shows the user read right switched on (a delrights form)" "value=\"delrights\"><input type=\"hidden\" name=\"rights\" value=\"$READ_ID\">" 1 -b "$J1" "$BASE/user/$RID/rights"
BEFORE="$(smoke_sql "$USER_RIGHTS")"
OUT="$(curl -s -o /dev/null -w '%{http_code}' -b "$J1" -c "$J1" -X POST -d "token=bogus&action=delrights&confirm=yes&module=user" "$BASE/user/$RID/rights")"
[ "$OUT" = "302" ] && [ "$(smoke_sql "$USER_RIGHTS")" = "$BEFORE" ] && ok "invalid token removes no right" || fail "invalid token removes no right" "$OUT"
TR="$(token_from "$J1" "/user/$RID/rights")"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TR" -d "action=delrights&confirm=yes&module=user&updatedmodulename=user" "$BASE/user/$RID/rights")"
[ "$OUT" = "302 $BASE/user/$RID/rights?updatedmodulename=user" ] && ok "POST /user/<id>/rights delrights module=user 302" || fail "POST /user/<id>/rights delrights" "$OUT"
[ "$(smoke_sql "$USER_RIGHTS")" = "0" ] && ok "rights of the user module removed" || fail "rights of the user module removed" "some left"
TR="$(token_from "$J1" "/user/$RID/rights")"
expect_code "GET with the legacy action parameters 200" 200 -b "$J1" "$BASE/user/$RID/rights?action=addrights&confirm=yes&module=user&token=$TR"
[ "$(smoke_sql "$USER_RIGHTS")" = "0" ] && ok "a GET writes no right" || fail "a GET writes no right" "rights added"
cleanup_smoke_users
[ "$(smoke_sql "SELECT COUNT(*) FROM llx_user WHERE login LIKE 'smoke\\_%'")" = "0" ] && ok "smoke users removed" || fail "smoke users removed" "some left"
CLEANUP_JAR=""
trap 'rm -f ${JARS[@]+"${JARS[@]}"}' EXIT

echo "== Invalid CSRF token (spec §8): action cancelled, warning displayed"
expect_location_suffix "POST /logout with an invalid token 302" "/index.php" -b "$J1" -c "$J1" -X POST -d "token=bogus" "$BASE/logout"
expect_code "still logged in after the invalid token" 200 -b "$J1" "$BASE/user/1"
curl -s -o /dev/null -b "$J1" -c "$J1" -X POST -d "token=bogus" "$BASE/logout"
expect_grep "invalid token warning displayed" "type: 'warning'" 1 -b "$J1" "$BASE/user/1"

echo "== New logout"
TL="$(token_from "$J1" /user/1)"
if [ -n "$TL" ]; then
	expect_location_suffix "POST /logout 302 to index.php" "/index.php" -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TL" "$BASE/logout"
else
	expect_location_suffix "GET /logout 302 to index.php" "/index.php" -b "$J1" -c "$J1" "$BASE/logout"
fi
expect_grep "legacy card shows login form after logout" 'name="username"' 1 -b "$J1" "$BASE/user/card.php?id=1"

echo "== Logout open redirect (logged in)"
newjar J3
T3="$(token_from "$J3" /login)"
curl -s -o /dev/null -b "$J3" -c "$J3" -X POST --data-urlencode "token=$T3" -d "actionlogin=login&loginfunction=loginfunction" \
	--data-urlencode "username=$LOGIN" --data-urlencode "password=$PASS_" "$BASE/login"
expect_code "J3 logged in" 200 -b "$J3" "$BASE/user/1"
OUT="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J3" -c "$J3" "$BASE/logout?urlfrom=//evil.example/x")"
case "$OUT" in "302 $BASE/index.php") ok "logout urlfrom=//evil stays on the host";; *) fail "logout urlfrom=//evil" "$OUT";; esac

echo "== Legacy login -> new world"
newjar J2
TLEG="$(token_from "$J2" /index.php)"
[ -n "$TLEG" ] || fail "legacy token present" "none"
ok "legacy token present"
curl -s -o /dev/null -b "$J2" -c "$J2" -X POST --data-urlencode "token=$TLEG" \
	-d "actionlogin=login&loginfunction=loginfunction" \
	--data-urlencode "username=$LOGIN" --data-urlencode "password=$PASS_" "$BASE/index.php"
expect_code "user/1 after legacy login" 200 -b "$J2" "$BASE/user/1"
curl -s -o /dev/null -b "$J2" -c "$J2" "$BASE/user/logout.php"
ok "legacy logout requested"
expect_code "user/1 after legacy logout 302" 302 -b "$J2" "$BASE/user/1"

echo "ALL OK"
