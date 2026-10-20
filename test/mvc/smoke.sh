#!/usr/bin/env bash
# HTTP smoke test of the MVC core coexisting with legacy pages.
# Usage: smoke.sh <base_url> [login] [password]
set -euo pipefail

BASE="${1:?usage: smoke.sh <base_url> [login] [password]}"
BASE="${BASE%/}"
LOGIN="${2:-admin}"
PASS_="${3:-admin}"

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
expect_grep "legacy card shows login form" 'name="username"' 1 "$BASE/user/card.php?id=1"
expect_code "user/1 anonymous 302" 302 "$BASE/user/1"
LOC="$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/user/1")"
case "$LOC" in "$BASE/login?backtopage="*) ok "user/1 redirects to login?backtopage";; *) fail "user/1 redirect target" "$LOC";; esac
expect_code "user/1 anonymous json 401" 401 "${JSON[@]}" "$BASE/user/1"
expect_grep "user/1 anonymous json body" '{"error":"not_logged"}' 1 "${JSON[@]}" "$BASE/user/1"
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
expect_grep "user/1.json login" '"login":"admin"' 1 -b "$J1" "$BASE/user/1.json"
expect_grep "user/1?format=json login" '"login":"admin"' 1 -b "$J1" "$BASE/user/1?format=json"
expect_code "user/abc 404" 404 -b "$J1" "$BASE/user/abc"
expect_code "user 404 (list in plan 2)" 404 -b "$J1" "$BASE/user"
expect_code "user/999999 404" 404 -b "$J1" "$BASE/user/999999"
expect_code "login while logged in 302" 302 -b "$J1" "$BASE/login"

echo "== New logout"
TL="$(token_from "$J1" /user/1)"
if [ -n "$TL" ]; then
	expect_location_suffix "POST /logout 302 to index.php" "/index.php" -b "$J1" -c "$J1" -X POST --data-urlencode "token=$TL" "$BASE/logout"
else
	expect_location_suffix "GET /logout 302 to index.php" "/index.php" -b "$J1" -c "$J1" "$BASE/logout"
fi
expect_grep "legacy card shows login form after logout" 'name="username"' 1 -b "$J1" "$BASE/user/card.php?id=1"

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
