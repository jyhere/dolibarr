#!/usr/bin/env bash
# HTTP smoke test of the MVC core coexisting with legacy pages.
# Usage: smoke.sh <base_url> [login] [password]
set -euo pipefail

BASE="${1:?usage: smoke.sh <base_url> [login] [password]}"
BASE="${BASE%/}"
LOGIN="${2:-admin}"
PASS_="${3:-admin}"
# SQL client used to remove the users created by the smoke test (default: the docker database of the dev setup)
SMOKE_MYSQL="${SMOKE_MYSQL:-mysql -h127.0.0.1 -P33306 -udolibarr_user -pdolibarr_password dolibarr}"
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

# smoke_sql <sql>: runs a statement with SMOKE_MYSQL, prints the result without header
smoke_sql() {
	local cmd
	read -r -a cmd <<< "$SMOKE_MYSQL"
	"${cmd[@]}" -N -B -e "$1" 2>/dev/null
}

# Removes the users created by the smoke test (login smoke_*) and what their creation wrote.
# Task 7 replaces this with the new delete route.
cleanup_smoke_users() {
	local ids="SELECT rowid FROM llx_user WHERE login LIKE 'smoke\\_%'"
	smoke_sql "DELETE r FROM llx_actioncomm_resources r JOIN llx_actioncomm a ON a.id = r.fk_actioncomm WHERE a.elementtype = 'user' AND a.fk_element IN ($ids);
		DELETE FROM llx_actioncomm WHERE elementtype = 'user' AND fk_element IN ($ids);
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
expect_grep "user/1 fichecenter" 'fichecenter' 1 -b "$J1" "$BASE/user/1"
expect_code "user/1/ trailing slash" 200 -b "$J1" "$BASE/user/1/"
expect_grep "user/1 Accept */* is HTML" '<html' 1 -H 'Accept: */*' -b "$J1" "$BASE/user/1"
expect_code "user/1 json 200" 200 -b "$J1" "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 json login" '"login":"admin"' 1 -b "$J1" "${JSON[@]}" "$BASE/user/1"
GREP_OPTS=-i expect_grep "user/1 json no pass" 'pass' 0 -b "$J1" "${JSON[@]}" "$BASE/user/1"
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
smoke_sql "SELECT 1" > /dev/null || fail "SQL client for the smoke users cleanup" "SMOKE_MYSQL=$SMOKE_MYSQL"
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
