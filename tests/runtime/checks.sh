#!/usr/bin/env bash
#
# Yo runtime checks.
#
#     ./tests/runtime/checks.sh
#
# The half the integration checks cannot reach. A console run has no session, so every
# `current`-audience path — which is the common one, the one a plugin gets by calling
# `Yo::success()` and nothing else — is only exercised over real HTTP with a real cookie jar.
#
# It also asserts the wire format itself: DataStar's SSE lines are a contract between the
# controller and a vendored client, and a change to either that the other does not follow produces
# a panel that silently stops updating rather than an error anybody sees.
#
# Signs in with `craft users/impersonate`, which hands back a one-hour URL — no password is typed
# anywhere, by anyone.
set -uo pipefail

SITE=${YO_SITE:-https://plugin-testing.ddev.site}
HARNESS=${YO_HARNESS:-$HOME/Sites/plugin-testing}
JAR=$(mktemp)
OUT=$(mktemp -d)
PASSED=0
FAILED=0

# Action URLs generated inside the control panel carry the CP trigger — `/admin/actions/…` — and
# `requireCpRequest()` reads exactly that. Posting to the bare `/actions/…` form is a 400, which
# is correct and cost half an hour to work out.
CPA="$SITE/admin/actions/yo/stream"

cleanup() { rm -rf "$JAR" "$OUT"; }
trap cleanup EXIT

say() { printf '\n%s\n' "$1"; }

check() {
  local label=$1
  shift

  if "$@" >/dev/null 2>&1; then
    PASSED=$((PASSED + 1))
    printf '  ✓ %s\n' "$label"
  else
    FAILED=$((FAILED + 1))
    printf '  ✗ %s\n' "$label"
  fi
}

get() { curl -skL -b "$JAR" -c "$JAR" "$@"; }

say 'Signing in'

URL=$(cd "$HARNESS" && ddev exec php craft users/impersonate admin 2>/dev/null | grep -o 'https://[^ ]*')

check 'an impersonation URL was issued' test -n "$URL"

get -o "$OUT/dashboard.html" "$URL"

check 'the control panel loads' grep -q 'id="global-container"' "$OUT/dashboard.html"

TOKEN=$(grep -o 'csrfTokenValue":"[^"]*' "$OUT/dashboard.html" | head -1 | cut -d'"' -f3)

check 'a CSRF token was found in the page' test -n "$TOKEN"

say 'The panel'

check 'the panel is on the page' grep -q 'id="yo-panel"' "$OUT/dashboard.html"
check 'so is its message list' grep -q 'id="yo-messages"' "$OUT/dashboard.html"
check 'the alien is drawn' grep -q 'yo-alien' "$OUT/dashboard.html"
check 'the browser config was registered' grep -q 'window.YoConfig' "$OUT/dashboard.html"
check 'DataStar is registered as a module' grep -q 'datastar.js' "$OUT/dashboard.html"
check 'the poller carries an interval' grep -q 'data-on-interval__duration' "$OUT/dashboard.html"
check 'the panel starts at a known anchor' grep -qE 'yo-panel--(top|bottom)-(left|center|right)' "$OUT/dashboard.html"

say 'Sending, over a real session'

curl -sk -b "$JAR" -c "$JAR" -X POST \
  -H "X-CSRF-Token: $TOKEN" \
  -H 'Accept: text/event-stream' \
  -o "$OUT/test.sse" \
  "$CPA/test?type=success"

check 'the response is a DataStar element patch' grep -q '^event: datastar-patch-elements' "$OUT/test.sse"
check 'it targets the message list' grep -q '^data: selector #yo-messages' "$OUT/test.sse"
check 'it patches the inside of it' grep -q '^data: mode inner' "$OUT/test.sse"
check 'the message is in the patch' grep -q 'That is what one looks like' "$OUT/test.sse"
check 'the signals come with it' grep -q '^event: datastar-patch-signals' "$OUT/test.sse"
check 'the count signal is 1' grep -q '"yoCount":1' "$OUT/test.sse"

say 'The session lane'

get -H 'Accept: text/event-stream' -o "$OUT/poll1.sse" "$CPA/poll"

check 'a poll after delivery finds nothing left' grep -q '"yoCount":0' "$OUT/poll1.sse"
check 'and renders the empty state' grep -q 'yo-empty' "$OUT/poll1.sse"

curl -sk -b "$JAR" -c "$JAR" -X POST -H "X-CSRF-Token: $TOKEN" -o /dev/null \
  "$CPA/test?type=error"

get -H 'Accept: text/event-stream' -o "$OUT/poll2.sse" "$CPA/poll"

check 'a sticky message survives being shown' grep -q '"yoCount":1' "$OUT/poll2.sse"

MSG_UID=$(grep -o 'data-yo-uid="[^"]*' "$OUT/poll2.sse" | head -1 | cut -d'"' -f2)

check 'the sticky message has a uid to dismiss it by' test -n "$MSG_UID"

curl -sk -b "$JAR" -c "$JAR" -X POST -H "X-CSRF-Token: $TOKEN" -o "$OUT/dismiss.sse" \
  "$CPA/dismiss?uid=$MSG_UID"

check 'dismissing it empties the panel' grep -q '"yoCount":0' "$OUT/dismiss.sse"

say 'Guards'

check 'a POST with no CSRF token is refused' bash -c \
  "curl -sk -o /dev/null -w '%{http_code}' -X POST '$SITE/actions/yo/stream/clear' | grep -q '400'"

# Craft answers a guest with a redirect to the login screen on an ordinary GET and a 403 on an
# Ajax one. Either is the guard working; a 200 is the only wrong answer.
check 'a signed-out request is not served the control panel channel' bash -c \
  "curl -sk -o /dev/null -w '%{http_code}' '$SITE/actions/yo/stream/poll' | grep -qE '302|400|403'"

check 'a channel nobody registered is refused' bash -c \
  "curl -sk -b '$JAR' -o /dev/null -w '%{http_code}' '$SITE/admin/actions/yo/stream/poll?channel=telepathy' | grep -q '403'"

say 'Position'

curl -sk -b "$JAR" -c "$JAR" -X POST -H "X-CSRF-Token: $TOKEN" -o "$OUT/position.sse" \
  "$CPA/position?position=top-left"

check 'a move is acknowledged as a signal' grep -q '"yoPosition":"top-left"' "$OUT/position.sse"

get -o "$OUT/dashboard2.html" "$SITE/admin"

check 'and the panel comes back where it was left' grep -q 'yo-panel--top-left' "$OUT/dashboard2.html"

curl -sk -b "$JAR" -c "$JAR" -X POST -H "X-CSRF-Token: $TOKEN" -o /dev/null \
  "$CPA/position?position=bottom-right"

check 'a position that is not one of the six is refused' bash -c \
  "curl -sk -b '$JAR' -o /dev/null -w '%{http_code}' -X POST -H 'X-CSRF-Token: $TOKEN' '$SITE/admin/actions/yo/stream/position?position=under-the-sofa' | grep -q '403'"

printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
test "$FAILED" -eq 0
